<?php

namespace Auth0\SDK\Helpers;

use Auth0\SDK\API\Helpers\RequestBuilder;
use Auth0\SDK\Helpers\Cache\CacheHandler;
use Auth0\SDK\Helpers\Cache\NoCacheHandler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;

use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;

/**
 * Class JWKFetcher.
 *
 * @package Auth0\SDK\Helpers
 */
class JWKFetcher
{
    const DEFAULT_JWK_PATH = '.well-known/jwks.json';

    /**
     * Cache handler or null for no caching.
     *
     * @var CacheHandler|null
     */
    private $cache;

    /**
     * Options for the Guzzle HTTP client.
     *
     * @var array
     */
    private $guzzleOptions;

    /**
     * JWKFetcher constructor.
     *
     * @param CacheHandler|null $cache         Cache handler or null for no caching.
     * @param array             $guzzleOptions Options for the Guzzle HTTP client.
     */
    public function __construct(CacheHandler $cache = null, array $guzzleOptions = [])
    {
        if ($cache === null) {
            $cache = new NoCacheHandler();
        }

        $this->cache         = $cache;
        $this->guzzleOptions = $guzzleOptions;
    }

    /**
     * Convert a certificate to PEM format.
     *
     * @param string $cert X509 certificate to convert to PEM format.
     *
     * @return string
     */
    protected function convertCertToPem($cert)
    {
        $output  = '-----BEGIN CERTIFICATE-----'.PHP_EOL;
        $output .= chunk_split($cert, 64, PHP_EOL);
        $output .= '-----END CERTIFICATE-----'.PHP_EOL;
        return $output;
    }

    /**
     * Fetch cert signature for token decoding.
     *
     * @param string      $jwks_url URL to the JWKS.
     * @param string|null $kid      Key ID to use; returns first JWK if $kid is null or empty.
     *
     * @return string|null - Null if a key could not be found for a key ID or if the JWKS is empty/invalid.
     */
    public function requestJwkSig($jwks_url, $kid = null)
    {
        $cache_key = $jwks_url.'|'.$kid;

        $cert = $this->cache->get($cache_key);
        if (! is_null($cert)) {
            return $cert;
        }

        $jwks = $this->requestJwks($jwks_url);
        $jwk  = $this->findJwk($jwks, $kid);

        if (!$this->subArrayHasEmptyFirstItem($jwk, 'x5c')) {
            $cert = $this->requestJwkX5c($jwk);
        } else if (!empty($jwk['kty']) && $jwk['kty'] == 'RSA') {
            $cert = $this->requetJwkRsa($jwk);
        } else {
            return null;
        }

        $this->cache->set($cache_key, $cert);
        return $cert;
    }

    /**
     * Fetch x509 cert for RS256 token decoding.
     *
     * @param mixed $jwk JWK keys structure
     *
     * @return string|null - Null if the JWKS is empty/invalid.
     */
    protected function requestJwkX5c($jwk)
    {
        $x5c = $this->convertCertToPem($jwk['x5c'][0]);
        return $x5c;
    }

    /**
     * Fetch RSA public key for RS256 token decoding.
     *
     * @param mixed $jwk JWK keys structure
     *
     * @return string|null - Null if the JWKS is empty/invalid.
     */
    protected function requetJwkRsa($jwk)
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $n = base64_decode(strtr($jwk['n'], '-_', '+/'), true);
        $e = base64_decode(strtr($jwk['e'], '-_', '+/'), true);
        $rsa = new RSA();
        $rsa->loadKey(
            [
                'e' => new BigInteger($e, 256),
                'n' => new BigInteger($n, 256)
            ]
        );
        return $rsa->getPublicKey();
    }

    /**
     * Get a JWKS from a specific URL.
     *
     * @param string $jwks_url URL to the JWKS.
     *
     * @return mixed|string
     *
     * @throws RequestException If $jwks_url is empty or malformed.
     * @throws ClientException  If the JWKS cannot be retrieved.
     *
     * @codeCoverageIgnore
     */
    protected function requestJwks($jwks_url)
    {
        $request = new RequestBuilder([
            'domain' => $jwks_url,
            'method' => 'GET',
            'guzzleOptions' => $this->guzzleOptions
        ]);
        return $request->call();
    }

    /**
     * Get a JWK from a JWKS using a key ID, if provided.
     *
     * @param array       $jwks JWKS to parse.
     * @param null|string $kid  Key ID to return; returns first JWK if $kid is null or empty.
     *
     * @return array|null Null if the keys array is empty or if the key ID is not found.
     *
     * @codeCoverageIgnore
     */
    private function findJwk(array $jwks, $kid = null)
    {
        if ($this->subArrayHasEmptyFirstItem($jwks, 'keys')) {
            return null;
        }

        if (! $kid) {
            return $jwks['keys'][0];
        }

        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Check if an array within an array has a non-empty first item.
     *
     * @param array|null $array Main array to check.
     * @param string     $key   Key pointing to a sub-array.
     *
     * @return boolean
     *
     * @codeCoverageIgnore
     */
    private function subArrayHasEmptyFirstItem($array, $key)
    {
        return empty($array) || empty($array[$key]) || ! is_array($array[$key]) || empty($array[$key][0]);
    }

    /**
     * Get JWKS URI from openid configuration manifest
     *
     * @param string $iss Identity provider endpoint
     *
     * @return string
     */
    public function getJwksUri($iss)
    {
        $openIdConfig = '.well-known/openid-configuration';
        $request = new RequestBuilder([
            'domain' => rtrim($iss, '/') . '/' . $openIdConfig,
            'method' => 'GET',
            'guzzleOptions' => $this->guzzleOptions
        ]);
        $config = $request->call();
        if (empty($config['jwks_uri'])) {
            # Retrocompatibility fix
            $config['jwks_uri'] = rtrim($iss, '/') . '/' . self::DEFAULT_JWK_PATH;
        }
        return $config['jwks_uri'];
    }

    /*
     * Deprecated
     */

    // phpcs:disable
    /**
     * Appends the default JWKS path to a token issuer to return all keys from a JWKS.
     *
     * @deprecated 5.4.0, use requestJwkX5c instead.
     *
     * @param string $iss
     *
     * @return array|mixed|null
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    public function fetchKeys($iss)
    {
        $url = "{$iss}" . self::DEFAULT_JWK_PATH;

        if (($secret = $this->cache->get($url)) === null) {
            $secret = [];

            $request = new RequestBuilder([
                'domain' => $this->getJwksUri($iss),
                'method' => 'GET',
                'guzzleOptions' => $this->guzzleOptions
            ]);
            $jwks    = $request->call();

            foreach ($jwks['keys'] as $key) {
                $secret[$key['kid']] = $this->convertCertToPem($key['x5c'][0]);
            }

            $this->cache->set($url, $secret);
        }

        return $secret;
    }
    // phpcs:enable
}
