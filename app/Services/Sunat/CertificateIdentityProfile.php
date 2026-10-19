<?php

namespace App\Services\Sunat;

use InvalidArgumentException;

/** Explicit server policy for one documented certificate profile. */
final readonly class CertificateIdentityProfile
{
    public string $profileId;

    public string $policyOid;

    public string $rucAttributeOid;

    /** Literal value prefix for the profile-authorized RUC attribute. */
    public string $rucAttributePrefix;

    public function __construct(
        string $profileId,
        string $policyOid,
        string $rucAttributeOid,
        string $rucAttributePrefix = '',
    ) {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,96}\z/D', $profileId) !== 1
            || ! self::isNumericOid($policyOid)
            || ! self::isNumericOid($rucAttributeOid)
            || strlen($rucAttributePrefix) > 32
            || preg_match('/\A[\x20-\x7E]*\z/D', $rucAttributePrefix) !== 1) {
            throw new InvalidArgumentException('certificate_identity_profile_invalid');
        }

        $this->profileId = $profileId;
        $this->policyOid = $policyOid;
        $this->rucAttributeOid = $rucAttributeOid;
        $this->rucAttributePrefix = $rucAttributePrefix;
    }

    public static function isNumericOid(string $oid): bool
    {
        $arcs = explode('.', $oid);
        if (count($arcs) < 2) {
            return false;
        }

        foreach ($arcs as $arc) {
            if (preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D', $arc) !== 1) {
                return false;
            }
        }

        $first = (int) $arcs[0];
        $second = (int) $arcs[1];

        return $first <= 2 && ($first === 2 || $second <= 39);
    }
}
