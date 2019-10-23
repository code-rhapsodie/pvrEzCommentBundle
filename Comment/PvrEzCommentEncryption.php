<?php

declare(strict_types=1);

/*
 * This file is part of the pvrEzComment package.
 *
 * (c) Philippe Vincent-Royol <vincent.royol@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace pvr\EzCommentBundle\Comment;

class PvrEzCommentEncryption implements pvrEzCommentEncryptionInterface
{
    const METHOD = 'AES-256-CBC';
    /**
     * @var Contains the secret key
     */
    protected $secretKey;

    /**
     * @param $secret
     */
    public function __construct($secret)
    {
        $this->secretKey = substr($secret, 0, 32);
    }

    /**
     * @param $string
     *
     * @return mixed
     */
    protected function safeB64encode($string)
    {
        $data = base64_encode($string);
        $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);

        return $data;
    }

    /**
     * @param $string
     *
     * @return string
     */
    protected function safeB64decode($string)
    {
        $data = str_replace(array('-', '_'), array('+', '/'), $string);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= substr('====', $mod4);
        }

        return base64_decode($data);
    }

    /**
     * @param $value        string to encode
     *
     * @return bool|string return crypt code or false
     */
    public function encode($value)
    {
        if (!$value) {
            return false;
        }
        $text = $value;
        $iv_size = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($iv_size);
        $cryptText = openssl_encrypt($text, self::METHOD, $this->secretKey, 0, $iv);

        return trim($this->safeB64encode($cryptText.$iv));
    }

    /**
     * @param $value        string to decode
     *
     * @return bool|string return decrypted code or false
     */
    public function decode($value)
    {
        if (!$value) {
            return false;
        }
        $ciphertext_dec = $this->safeB64decode($value);
        $iv_size = openssl_cipher_iv_length(self::METHOD);
        $iv = substr($ciphertext_dec, 0, $iv_size);
        $cryptText = substr($ciphertext_dec, $iv_size);
        $decryptText = openssl_decrypt($cryptText, self::METHOD, $this->secretKey, 0, $iv);

        return trim($decryptText);
    }
}
