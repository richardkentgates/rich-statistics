<?php
    if ( ! function_exists('curl_init'))
        throw new Exception('Freemius needs the CURL PHP extension.');

    require_once(dirname(__FILE__) . '/FreemiusBase.php');

    define('FS_SDK__USER_AGENT', 'fs-php-' . Freemius_Api_Base::VERSION);

    $curl_version = curl_version();
    define('FS_API__PROTOCOL', version_compare($curl_version['version'], '7.37', '>=') ? 'https' : 'http');

    if ( ! defined('FS_API__ADDRESS'))
        define('FS_API__ADDRESS', FS_API__PROTOCOL . '://api.freemius.com');
    if ( ! defined('FS_API__SANDBOX_ADDRESS'))
        define('FS_API__SANDBOX_ADDRESS', FS_API__PROTOCOL . '://sandbox-api.freemius.com');

    if ( ! class_exists( 'Freemius_Api' ) ) {
        class Freemius_Api extends Freemius_Api_Base
        {
            public static $CURL_OPTS = array(
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_USERAGENT      => FS_SDK__USER_AGENT,
                CURLOPT_HTTPHEADER     => array()
            );

            public function __construct($pScope, $pID, $pPublic, $pSecret = false, $pSandbox = false)
            {
                if (is_bool($pSecret))
                    $pSecret = $pPublic;

                parent::Init($pScope, $pID, $pPublic, $pSecret, $pSandbox);
            }

            public function GetUrl($pCanonizedPath = '')
            {
                return ($this->_sandbox ? FS_API__SANDBOX_ADDRESS : FS_API__ADDRESS) . $pCanonizedPath;
            }

            private static $_clock_diff = 0;

            public static function SetClockDiff($pSeconds)
            {
                self::$_clock_diff = $pSeconds;
            }

            protected function SignRequest($pResourceUrl, $pMethod, &$opts, $pJsonEncodedParams, $pContentType)
            {
                $auth = $this->GenerateAuthorizationParams(
                    $pResourceUrl,
                    $pMethod,
                    $pJsonEncodedParams,
                    $pContentType
                );

                $opts[CURLOPT_HTTPHEADER][] = ('Date: ' . $auth['date']);
                $opts[CURLOPT_HTTPHEADER][] = ('Authorization: ' . $auth['authorization']);

                if ( ! empty($auth['content_md5']))
                    $opts[CURLOPT_HTTPHEADER][] = ('Content-MD5: ' . $auth['content_md5']);
            }

            private function GenerateAuthorizationParams(
                $pResourceUrl,
                $pMethod = 'GET',
                $pJsonEncodedParams = '',
                $pContentType = ''
            )
            {
                $pMethod = strtoupper($pMethod);

                $eol         = "\n";
                $content_md5 = '';
                $now         = (time() - self::$_clock_diff);
                $date        = date('r', $now);

                if (in_array($pMethod, array('POST', 'PUT')) && ! empty($pJsonEncodedParams))
                    $content_md5 = md5($pJsonEncodedParams);

                $string_to_sign = implode($eol, array(
                    $pMethod,
                    $content_md5,
                    $pContentType,
                    $date,
                    $pResourceUrl
                ));

                $auth_type = ($this->_secret !== $this->_public) ? 'FS' : 'FSP';

                $auth = array(
                    'date'          => $date,
                    'authorization' => $auth_type . ' ' . $this->_id . ':' .
                        $this->_public . ':' .
                        self::Base64UrlEncode(hash_hmac(
                            'sha256', $string_to_sign, $this->_secret
                        ))
                );

                if ( ! empty($content_md5))
                    $auth['content_md5'] = $content_md5;

                return $auth;
            }

            function GetSignedUrl($pPath)
            {
                $resource     = explode('?', $this->CanonizePath($pPath));
                $pResourceUrl = $resource[0];

                $auth = $this->GenerateAuthorizationParams($pResourceUrl);

                return $this->GetUrl(
                    $pResourceUrl . '?' .
                    (1 < count($resource) && ! empty($resource[1]) ? $resource[1] . '&' : '') .
                    http_build_query(array(
                        'auth_date' => $auth['date'],
                        'authorization' => $auth['authorization']
                    )));
            }

            public function MakeRequest($pCanonizedPath, $pMethod = 'GET', $pParams = array(), $pFileParams = array(), $ch = null)
            {
                if ( ! $ch)
                    $ch = curl_init();

                $opts = self::$CURL_OPTS;

                if ( ! is_array($opts[CURLOPT_HTTPHEADER]))
                    $opts[CURLOPT_HTTPHEADER] = array();

                $content_type = 'application/json';
                $json_encoded_params = empty($pParams) ?
                    '' :
                    json_encode($pParams);

                $overidden_method = $pMethod;

                if ('POST' === $pMethod || 'PUT' === $pMethod)
                {
                    if ( ! empty($pFileParams))
                    {
                        $data = empty($json_encoded_params) ?
                            '' :
                            array('data' => $json_encoded_params);

                        $json_encoded_params = '';

                        $boundary = ('----' . uniqid());
                        $post_fields = $this->GenerateMultipartBody($data, $pFileParams, $boundary);
                        $content_type = "multipart/form-data; boundary={$boundary}";

                        if ('PUT' === $pMethod)
                        {
                            $query = parse_url($pCanonizedPath, PHP_URL_QUERY);
                            $pCanonizedPath .= (is_string($query) ? '&' : '?') . 'method=PUT';
                            $overidden_method = $pMethod;
                            $pMethod = 'POST';
                        }
                    }
                    else
                    {
                        $post_fields = $json_encoded_params;
                    }

                    if (is_array($pParams) && 0 < count($pParams))
                    {
                        $opts[CURLOPT_POST] = count($pParams);
                        $opts[CURLOPT_POSTFIELDS] = $post_fields;
                    }

                    $opts[CURLOPT_RETURNTRANSFER] = true;
                }
                else if (('GET' === $pMethod || 'DELETE' === $pMethod) && ! empty($pParams))
                {
                    $pCanonizedPath = $this->AddQueryParams($pCanonizedPath, $pParams);
                }

                $opts[CURLOPT_HTTPHEADER][] = "Content-Type: $content_type";

                $request_url = $this->GetUrl($pCanonizedPath);

                $opts[CURLOPT_URL] = $request_url;
                $opts[CURLOPT_CUSTOMREQUEST] = $pMethod;

                $resource = explode('?', $pCanonizedPath);
                $this->SignRequest($resource[0], $overidden_method, $opts, $json_encoded_params, $content_type);

                $opts[CURLOPT_HTTPHEADER][] = 'Expect:';

                if ('https' === substr(strtolower($request_url), 0, 5))
                {
                    $opts[CURLOPT_SSL_VERIFYHOST] = false;
                    $opts[CURLOPT_SSL_VERIFYPEER] = false;
                }

                curl_setopt_array($ch, $opts);
                $result = curl_exec($ch);

                if (false === $result && empty($opts[CURLOPT_IPRESOLVE]))
                {
                    $matches = array();
                    $regex = '/Failed to connect to ([^:].*): Network is unreachable/';
                    if (preg_match($regex, curl_error($ch), $matches))
                    {
                        if (strlen(@inet_pton($matches[1])) === 16)
                        {
                            self::errorLog('Invalid IPv6 configuration on server, Please disable or get native IPv6 on your server.');
                            self::$CURL_OPTS[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
                            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                            $result = curl_exec($ch);
                        }
                    }
                }

                if ($result === false)
                {
                    $e = new Freemius_Exception(array(
                        'error' => array(
                            'code' => curl_errno($ch),
                            'message' => curl_error($ch),
                            'type' => 'CurlException',
                        ),
                    ));

                    curl_close($ch);
                    throw $e;
                }

                curl_close($ch);

                return $result;
            }

            private function GenerateMultipartBody($pParams, $pFileParams, $pBoundary)
            {
                $body = '';

                if ( ! empty($pParams))
                {
                    foreach ($pParams as $name => $value)
                    {
                        $body = ('--' . $pBoundary . PHP_EOL) .
                            ("Content-Disposition: form-data; name=\"{$name}\"" . PHP_EOL) .
                            PHP_EOL .
                            ($value . PHP_EOL);
                    }
                }

                foreach ($pFileParams as $name => $file_path)
                {
                    $filename = basename($file_path);

                    $body .=
                        ('--' . $pBoundary . PHP_EOL) .
                        ("Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"" . PHP_EOL) .
                        ('Content-Type: ' . $this->GetMimeContentType($file_path) . PHP_EOL) .
                        PHP_EOL .
                        (file_get_contents($file_path) . PHP_EOL);
                }

                $body .= ('--' . $pBoundary . '--');

                return $body;
            }

            private function GetMimeContentType($pFilename)
            {
                if (function_exists('mime_content_type'))
                    return mime_content_type($pFilename);

                $mime_types = array(
                    'zip'  => 'application/zip',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                );

                $ext = explode('.', $pFilename)[1];

                if ( ! isset($mime_types[$ext]))
                    throw new Exception('Unknown file type');

                return $mime_types[$ext];
            }
        }
    }
