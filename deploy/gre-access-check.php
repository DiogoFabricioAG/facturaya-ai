<?php

// Read-only GRE API access check for RUC 10410461248: no database writes, no documents issued.
// Values arrive through stdin (empty line = use the stored value) and are never printed.
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');

try {
    $read = static fn (int $max): string => rtrim((string) fgets(STDIN, $max), "\r\n");
    [$id, $secret, $user, $password] = [$read(2048), $read(2048), $read(256), $read(1024)];

    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $company = \App\Models\Company::where('ruc', '10410461248')->firstOrFail();
    if (($id === '') !== ($secret === '') || ($user === '') !== ($password === '')) {
        throw new RuntimeException('incomplete pair');
    }
    $source = ['credencial' => $id === '' ? 'guardada' : 'ingresada', 'usuario_sol' => $user === '' ? 'guardado' : 'ingresado'];
    $id = $id === '' ? (string) $company->gre_client_id : $id;
    $secret = $secret === '' ? (string) $company->gre_client_secret : $secret;
    $user = strtoupper($user === '' ? (string) $company->sol_user : $user);
    $password = $password === '' ? (string) $company->sol_password : $password;

    $mask = static fn (string $v): string => mb_substr(preg_replace('/[A-Za-z0-9._~+\/=-]{32,}/', '[…]', $v) ?? '', 0, 300);
    $http = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false]);
    $response = $http->post('https://api-seguridad.sunat.gob.pe/v1/clientessol/'.rawurlencode($id).'/oauth2/token/', ['form_params' => [
        'grant_type' => 'password', 'scope' => 'https://api-cpe.sunat.gob.pe', 'client_id' => $id, 'client_secret' => $secret,
        'username' => $company->ruc.$user, 'password' => $password,
    ]]);
    unset($secret, $password);
    $token = (string) (json_decode((string) $response->getBody(), true)['access_token'] ?? '');
    $result = ['prueba' => $source, 'token_http' => $response->getStatusCode()];
    if ($token === '') {
        $result['token_error'] = $mask((string) $response->getBody());
        $result['conclusion'] = 'SUNAT no entrego token con esta combinacion.';
    } else {
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true) ?: [];
        $tipo = $claims['userdata']['map']['tipUsuario'] ?? null;
        $result['tipo_usuario'] = $tipo === '0' ? 'principal' : ($tipo === '1' ? 'secundario' : $tipo);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $base = 'https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes';
        // A fake ticket and an empty archive: SUNAT cannot register anything from these requests.
        $query = $http->get($base.'/envios/00000000-0000-0000-0000-000000000000', ['headers' => $headers]);
        $send = $http->post($base.'/'.$company->ruc.'-09-T001-99999999', ['headers' => $headers,
            'json' => ['archivo' => ['nomArchivo' => '', 'arcGreZip' => '', 'hashZip' => '']]]);
        $result['consulta_http'] = $query->getStatusCode();
        $result['envio_vacio_http'] = $send->getStatusCode();
        $result['envio_vacio_respuesta'] = $mask((string) $send->getBody());
        $result['conclusion'] = $query->getStatusCode() === 401 || $send->getStatusCode() === 401
            ? 'SUNAT entrega token pero NO autoriza la API de guias con esta combinacion.'
            : 'ACCESO OK: esta combinacion puede usar la API de guias.';
    }
    unset($token);
    echo json_encode($result + ['documentos_emitidos' => 0, 'datos_guardados' => 0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "No se completo la prueba. No se muestran detalles sensibles; revisa que ingresaste ID y clave, o usuario y clave, en pareja.\n");
    exit(1);
}
