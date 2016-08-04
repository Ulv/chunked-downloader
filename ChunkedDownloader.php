<?php
/**
 * Класс для скачивания больших файлов по HTTP
 *
 * Фичи:
 * - удаленный файл скачивается по частям. Размер устанавливается в константе
 * класса CHUNK_SIZE_MB
 * - есть возможность использовать SSL (скачивание файла по HTTPS)
 * - есть возможность использовать простую HTTP аутентификацию (basic HTTP
 * authentication)
 *
 * Пример использования:
 *
 * <code>
 * $downloader = new ChunkedDownloader();
 * $downloader->setSourceFile($remoteUri);
 * $downloader->setDestinationFile($cacheFile);
 * $downloader->setUseSSL(true);
 * $downloader->setHttpAuth(config("learner.user"), config("learner.pass"));
 * $downloader->download();
 * </code>
 *
 * PHP version 5.4
 *
 * @author    Vladimir Chmil <vladimir.chmil@gmail.com>
 * @license   MIT
 */

namespace Ulv\Neurallearner\Dataproviders;

/**
 * В классе есть поддержка HTTP Basic authentication и SSL
 *
 * @package packages\ulv\neurallearner\src\Dataproviders
 */
class ChunkedDownloader {
    /**
     * Размер скачиваемого чанка, Мб
     */
    const CHUNK_SIZE_MB = 5;

    /**
     * Порт для fsockopen(), если SSL не используется
     */
    const HTTP_PORT = 80;

    /**
     * Порт для fsockopen(), если используется SSL
     */
    const HTTPS_PORT = 443;

    /**
     * URI исходного файла
     * @var string
     */
    private $sourceFile = '';

    /**
     * Путь к файлу назначения
     * @var string
     */
    private $destinationFile = '';

    /**
     * Используем SSL?
     * @var bool
     */
    private $useSSL = false;

    /**
     * Используем HTTP-аутентифтикацию? (basic)
     * @var bool
     */
    private $useHttpAuth = false;

    /**
     * Логин
     * @var string
     */
    private $httpLogin = '';

    /**
     * @var string
     */
    private $httpPassword = '';

    /**
     * @param string $sourceFile
     */
    public function setSourceFile($sourceFile) {
        $this->sourceFile = $sourceFile;
    }

    /**
     * @param string $destinationFile
     */
    public function setDestinationFile($destinationFile) {
        $this->destinationFile = $destinationFile;
    }

    /**
     * @param boolean $useSSL
     */
    public function setUseSSL($useSSL) {
        $this->useSSL = $useSSL;
    }

    /**
     * @param boolean $useHttpAuth
     */
    public function setHttpAuth($login, $password) {
        $this->useHttpAuth  = true;
        $this->httpLogin    = $login;
        $this->httpPassword = $password;
    }

    /**
     * Скачивает удаленный файл
     *
     * @return int размер файла|bool false в случае ошибки
     */
    public function download() {
        if ($this->sourceFile && $this->destinationFile) {
            return $this->downloadByChunks($this->sourceFile, $this->destinationFile);
        }

        return false;
    }

    /**
     * Copy remote file over HTTP one small chunk at a time.
     *
     * Function taken from here:
     * https://stackoverflow.com/questions/4000483/how-download-big-file-using-php-low-memory-usage?answertab=votes#tab-top
     *
     * with SSL/HTTP auth modifications
     *
     * @param $infile  full URL to the remote file
     * @param $outfile path where to save the file
     */
    private function downloadByChunks($infile, $outfile) {
        $chunksize = self::CHUNK_SIZE_MB * (1024 * 1024); // 5 Megs

        /**
         * parse_url breaks a part a URL into it's parts, i.e. host, path,
         * query string, etc.
         */
        $parts    = parse_url($infile);
        $i_handle = fsockopen($this->getRemoteHost($parts['host']), $this->getRemotePort(), $errstr, $errcode, 5);
        $o_handle = fopen($outfile, 'wb');

        if ($i_handle == false || $o_handle == false) {
            return false;
        }

        if (!empty($parts['query'])) {
            $parts['path'] .= '?' . $parts['query'];
        }

        /**
         * Send the request to the server for the file
         */
        $request = "GET {$parts['path']} HTTP/1.1\r\n";
        $request .= $this->getAuthHeader();
        $request .= "Host: {$parts['host']}\r\n";
        $request .= "User-Agent: Mozilla/5.0\r\n";
        $request .= "Keep-Alive: 115\r\n";
        $request .= "Connection: keep-alive\r\n\r\n";

        fwrite($i_handle, $request);

        /**
         * Now read the headers from the remote server. We'll need
         * to get the content length.
         */
        $headers = [];
        while (!feof($i_handle)) {
            $line = fgets($i_handle);
            if ($line == "\r\n") {
                break;
            }
            $headers[] = $line;
        }

        /**
         * Look for the Content-Length header, and get the size
         * of the remote file.
         */
        $length = 0;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Length:') === 0) {
                $length = (int) str_replace('Content-Length: ', '', $header);
                break;
            }
        }

        /**
         * Start reading in the remote file, and writing it to the
         * local file one chunk at a time.
         */
        $cnt = 0;
        while (!feof($i_handle)) {
            $buf   = '';
            $buf   = fread($i_handle, $chunksize);
            $bytes = fwrite($o_handle, $buf);
            if ($bytes == false) {
                return false;
            }
            $cnt += $bytes;

            /**
             * We're done reading when we've reached the conent length
             */
            if ($cnt >= $length) {
                break;
            }
        }

        fclose($i_handle);
        fclose($o_handle);

        return $cnt;
    }

    /**
     * Возвращает uri хоста для fsockopen()
     *
     * @param $host
     *
     * @return string
     */
    private function getRemoteHost($host) {
        if (!$this->useSSL) {
            return $host;
        }

        return 'ssl://' . $host;
    }

    /**
     * Метод возвращает порт для fsockopen
     *
     * @return int
     */
    private function getRemotePort() {
        if ($this->useSSL) {
            return self::HTTPS_PORT;
        }

        return self::HTTP_PORT;
    }

    /**
     * Метод возвращает заголовок для HTTP-аутентификации или пустую строку
     *
     * @return string
     */
    private function getAuthHeader() {
        if ($this->useHttpAuth) {
            return "Authorization: Basic " . base64_encode(
                $this->httpLogin . ":" .
                $this->httpPassword
            ) . "\r\n";
        }

        return '';
    }
}