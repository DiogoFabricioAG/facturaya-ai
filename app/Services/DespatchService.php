<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Despatch;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DespatchService
{
    public function __construct(private readonly GreenterDespatchGateway $gateway, private readonly InvoiceSequenceService $sequences) {}

    public function draft(Company $company, array $input): Despatch
    {
        return DB::transaction(function () use ($company, $input): Despatch {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $invoice = $this->invoice($company, $input['invoice_id']);
            abort_if($input['issue_date'] < $invoice->draft->issue_date->format('Y-m-d'), 422, 'La guía no puede tener una fecha anterior a su factura.');
            $payload = $input;
            unset($payload['idempotency_key']);
            if (in_array($payload['vehicle_category'] ?? null, ['M1', 'L'], true)) {
                unset($payload['vehicle'], $payload['driver']);
            }
            $digest = CanonicalJson::digest(['company' => $company->id, 'environment' => $company->sunat_environment,
                'driver' => $company->sunat_driver, 'fiscal_revision' => $company->fiscal_revision ?? 0,
                'gre_revision' => $company->gre_revision ?? 0, 'payload' => $payload]);
            $existing = Despatch::where('company_id', $company->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->confirmation_digest, $digest), 409, 'La clave ya fue usada para otra guía o configuración.');

                return $existing;
            }

            return Despatch::create(['company_id' => $company->id, 'invoice_id' => $invoice->id,
                'environment' => $company->sunat_environment, 'driver' => $company->sunat_driver,
                'fiscal_revision' => $company->fiscal_revision ?? 0, 'gre_revision' => $company->gre_revision ?? 0,
                'idempotency_key' => $input['idempotency_key'], 'confirmation_digest' => $digest, 'payload' => $payload, 'status' => 'draft', 'series' => 'T001']);
        });
    }

    public function issue(Company $company, Despatch $guide, string $digest): Despatch
    {
        $claimed = DB::transaction(function () use (&$company, &$guide, $digest): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $guide = Despatch::query()->lockForUpdate()->findOrFail($guide->id);
            abort_unless($guide->company_id === $company->id, 404);
            abort_unless(hash_equals($guide->confirmation_digest, $digest), 409, 'Revisa y confirma nuevamente los datos de la guía.');
            if ($guide->status !== 'draft') {
                return false; // Replay never sends a second time, including unknown outcomes.
            }
            $this->assertConfiguration($company, $guide);
            $this->invoice($company, $guide->invoice_id);
            abort_if($guide->payload['transfer_date'] < now('America/Lima')->format('Y-m-d'), 422, 'La fecha de traslado ya pasó; prepara y revisa una nueva guía.');
            $guide->update(['status' => 'processing', 'correlative' => $this->sequences->next($company, $guide->series, $guide->environment)]);

            return true;
        });
        if (! $claimed) {
            return $guide;
        }
        $sent = false;
        try {
            $xml = $this->gateway->xml($company, $guide, $this->invoice($company, $guide->invoice_id));
            $directory = 'companies/'.$company->id.'/despatches/'.$guide->id;
            $path = $directory.'/'.$company->ruc.'-09-'.$guide->series.'-'.$guide->correlative.'.xml';
            if (! Storage::disk('local')->put($path, $xml)) {
                throw new \RuntimeException('gre_xml_storage_failed');
            }
            $guide->update(['xml_path' => $path]);
            $sent = true;
            $ticket = $this->gateway->send($company, $guide, $xml);
            $guide->update(['ticket' => $ticket, 'status' => 'pending', 'message' => 'Envío registrado. Falta consultar la aceptación.']);
        } catch (GreSendException $error) {
            // SUNAT's code and message are business data already sanitized by the gateway; never the raw exception.
            Log::warning($error->definitive ? 'gre_issue_rejected' : 'gre_issue_unconfirmed', ['guide_id' => $guide->id,
                'phase' => $error->stage, 'sunat_code' => $error->sunatCode, 'sunat_message' => $error->sunatMessage]);
            $reason = $error->sunatCode.': '.$error->sunatMessage;
            $guide->update($error->definitive
                ? ['status' => 'send_failed', 'response_code' => $error->sunatCode,
                    'message' => mb_substr('SUNAT no recibió la guía ('.$reason.'). No quedó registrada: corrige el dato y prepara una nueva guía.', 0, 500)]
                : ['status' => 'unknown', 'response_code' => $error->sunatCode,
                    'message' => mb_substr('No pudimos confirmar el envío ('.$reason.'). No vuelvas a emitir esta guía; requiere revisión.', 0, 500)]);
        } catch (Throwable $error) {
            // Never log raw transport exceptions: API errors can contain credentials or tokens.
            Log::warning('gre_issue_unconfirmed', ['guide_id' => $guide->id, 'phase' => $sent ? 'send' : 'prepare', 'error_type' => $error::class]);
            $guide->update(['status' => $sent ? 'unknown' : 'preparation_failed', 'message' => $sent
                ? 'No pudimos confirmar el envío. No vuelvas a emitir esta guía; requiere revisión.'
                : 'No se pudo preparar la guía. Revisa la configuración antes de continuar.']);
        }

        return $guide->fresh();
    }

    public function refresh(Company $company, Despatch $guide): Despatch
    {
        if ($guide->status !== 'pending' || ! $guide->ticket) {
            return $guide;
        }
        $this->assertConfiguration($company, $guide);
        try {
            $result = $this->gateway->status($company, $guide);
            if ($result['status'] === 'pending') {
                return $guide;
            }
            if (! in_array($result['status'], ['accepted', 'rejected'], true) || empty($result['cdr'])) {
                throw new \RuntimeException('gre_status_incomplete');
            }
            $path = 'companies/'.$company->id.'/despatches/'.$guide->id.'/R-'.$guide->series.'-'.$guide->correlative.($guide->driver === 'fake' ? '.json' : '.zip');
            if (! Storage::disk('local')->put($path, $result['cdr'])) {
                throw new \RuntimeException('gre_cdr_storage_failed');
            }
            $guide->update(['status' => $result['status'], 'response_code' => $result['code'], 'cdr_path' => $path,
                'message' => $guide->driver === 'fake' ? 'SIMULACIÓN local: no enviada a SUNAT.'
                    : ($result['status'] === 'accepted' ? 'Guía aceptada por SUNAT.' : 'Guía rechazada por SUNAT; revisa su CDR.')]);
        } catch (Throwable $error) {
            Log::warning('gre_status_unconfirmed', ['guide_id' => $guide->id, 'error_type' => $error::class]);
            // A failed query must preserve the ticket and permit querying again, never resending.
            $guide->update(['message' => 'No se pudo confirmar el estado. Consulta nuevamente; no se reenviará la guía.']);
        }

        return $guide->fresh();
    }

    private function invoice(Company $company, string $id): Invoice
    {
        $invoice = Invoice::where('company_id', $company->id)->with('draft')->findOrFail($id);
        abort_unless($invoice->status === 'accepted' && $invoice->document_type === '01'
            && $invoice->sunat_environment === $company->sunat_environment && $invoice->draft?->customer_document_type === '6',
            422, 'Se requiere una factura aceptada de esta empresa y entorno, con cliente RUC.');

        return $invoice;
    }

    private function assertConfiguration(Company $company, Despatch $guide): void
    {
        abort_unless($company->active && $guide->environment === $company->sunat_environment
            && $guide->driver === $company->sunat_driver && $guide->fiscal_revision === (int) $company->fiscal_revision
            && $guide->gre_revision === (int) $company->gre_revision, 409, 'Cambió la configuración de empresa; revisa la guía.');
        abort_unless(in_array($guide->driver, ['fake', 'greenter'], true), 422);
        if ($guide->driver === 'greenter') {
            abort_unless($company->sunat_environment === 'production' && $company->gre_enabled && $company->hasSunatCredentials()
                && filled($company->gre_client_id) && filled($company->gre_client_secret), 422,
                'Configura y habilita las credenciales API de GRE. No se envían guías beta al servicio de producción.');
            abort_unless(InvoiceSequence::where('company_id', $company->id)->where('sunat_environment', 'production')
                ->where('series', $guide->series)->where('next_number', '<=', 99999999)->exists(), 422,
                'Confirma el próximo correlativo GRE de producción antes de enviar.');
        }
    }
}
