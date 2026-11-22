<?php

namespace App\Support;

use RuntimeException;

final class PassportKeyPermissions
{
    /**
     * Restrict existing file-backed Passport keys without generating or changing key material.
     *
     * @param  list<mixed>  $keyPaths
     * @return list<string>
     */
    public function secureExisting(array $keyPaths): array
    {
        $secured = [];
        $seen = [];

        foreach ($keyPaths as $keyPath) {
            if (! is_string($keyPath) || $keyPath === '' || strlen($keyPath) > 4096
                || str_contains($keyPath, "\0") || str_contains($keyPath, "\n")) {
                continue;
            }

            if (str_starts_with($keyPath, 'file://')) {
                $keyPath = substr($keyPath, 7);
            }

            if (isset($seen[$keyPath])) {
                continue;
            }
            $seen[$keyPath] = true;

            // Passport also accepts inline PEM values. They are not filesystem paths.
            if (str_starts_with(ltrim($keyPath), '-----BEGIN')) {
                continue;
            }

            if (! is_file($keyPath)) {
                continue;
            }

            if (! @chmod($keyPath, 0600)) {
                throw new RuntimeException('Unable to set restrictive permissions on an existing Passport key file.');
            }

            clearstatcache(true, $keyPath);
            $permissions = fileperms($keyPath);
            if ($permissions === false || ($permissions & 0777) !== 0600) {
                throw new RuntimeException('An existing Passport key file does not have the required 0600 permissions.');
            }

            $secured[] = $keyPath;
        }

        return $secured;
    }
}
