<?php

/**
 * XUrl
 *
 * @author      hfcorriez <hfcorriez@gmail.com>
 *
 * @todo 支持上传文件
 * @todo 支持设置Cookie
 * @todo 支持只写模式
 */

namespace CodeGun\XUrl;

class XUrl
{
    const TYPE_CURL = 'curl';
    const TYPE_SOCKET = 'socket';
    const TYPE_FSOCK = 'fsock';

    const HTTP_VERSION_1_0 = '1.0';
    const HTTP_VERSION_1_1 = '1.1';

    protected $proxy;
    protected $type = self::TYPE_FSOCK;
    protected $post;
    protected $timeout = 0;
    protected $user_agent;
    protected $http_version = self::HTTP_VERSION_1_1;
    protected $header = array();
    protected $result = array();
    protected $error = false;

    /**
     * 设置代理（目前仅支持http代理）
     *
     * @param string $str           支持ip:port方式
     * @return XUrl
     */
    public function setProxy($str)
    {
        list($this->proxy['host'], $this->proxy['port']) = explode(':', $str);
        return $this;
    }

    /**
     * 设置使用的请求类型，默认为fsock方式
     *
     * @param string $type          0|1|2
     * @return XUrl
     */
    public function setType($type = self::TYPE_FSOCK)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * 设置过期时间
     * @param int $sec              秒数
     * @return XUrl
     */
    public function setTimeout($sec = 0)
    {
        $this->timeout = $sec;
        return $this;
    }

    /**
     * 设置header
     *
     * @param mixed  $name           header名称或者数组方式
     * @param string $value          header值
     * @return XUrl
     */
    public function setHeader($name, $value = '')
    {
        if (!$value) {
            if (is_array($name)) {
                foreach ($name as $k => $v) {
                    $this->header[] = $k . (strpos($k, ':') === false ? : ': ' . $v);
                }
            } elseif (strpos($name, ':') !== false) {
                $this->header[] = $name;
            }
        } else {
            $this->header[] = $name . ': ' . $value;
        }
        return $this;
    }

    /**
     * 设置HTTP协议版本号
     *
     * @param string $version           支持1.0,1.1
     * @return XUrl
     */
    public function setHttpVersion($version = self::HTTP_VERSION_1_1)
    {
        $this->http_version = $version;
        return $this;
    }

    /**
     * 设置User-Agent
     *
     * @param string $agent
     * @return XUrl
     */
    public function setUserAgent($agent)
    {
        $this->user_agent = $agent;
        return $this;
    }

    /**
     * 设置POST数据
     */
    public function setPost($post)
    {
        if (is_array($post)) {
            $tmp = array();
            foreach ($post as $k => $v) {
                $tmp[] = "{$k}={$v}";
            }
            $post = join('&', $tmp);
        }
        $this->post = $post;
        return $this;
    }

    /**
     * 获取错误信息
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 获取数据
     * @param string $url           要获取的URL
     * @return array
     */
    public function fetch($url)
    {
        $this->setError(false);
        if (!$this->checkUrl($url)) return $this->result;

        $method = $this->type . 'Request';
        return $this->parseResponse($this->$method($url));
    }

    /**
     * 解析数据
     * @param string $response     要解析的数据
     * @return array
     */
    public function parseResponse($response)
    {
        $result = &$this->result;

        if (!$response) {
            $this->setError(true);
            return $result;
        }

        $pos = strpos($response, "\r\n\r\n");
        $head = substr($response, 0, $pos);
        $headers = explode("\r\n", $head);
        $http_info = array();

        foreach ($headers as $k => $line) {
            if ($k == 0) {
                preg_match("/ (\d+) /", $line, $match);
                $result['status_code'] = $match[1];
                $http_info['status_line'] = $line;
                continue;
            }
            list($key, $value) = explode(":", $line);
            $http_info[strtolower(trim($key))] = trim($value);
        }
        $status = substr($head, 0, strpos($head, "\r\n"));
        $body = substr($response, $pos + 4);
        if (preg_match("/^HTTP\/\d\.\d\s(\d{3,4})\s/", $status, $matches)) {
            if ($this->type != self::TYPE_CURL && !empty($http_info['transfer-encoding']) && $http_info['transfer-encoding'] == 'chunked') {
                $result['body'] = self::decodeChunk($body);
            } else {
                $result['body'] = $body;
            }
        } else {
            $result['body'] = false;
        }
        $result['header'] = $http_info;
        return $result;
    }

    /**
     * 通过socket获取数据
     *
     * @param string $url           要获取的URL
     * @return bool|string
     */
    public function socketRequest($url)
    {
        $response = false;
        if (function_exists('socket_create')) {
            $u = $this->parseUrl($url);
            if ($this->proxy) {
                $u['host'] = $this->proxy['host'];
                $u['port'] = $this->proxy['port'];
            }
            $fsock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$fsock) return false;

            if ($this->timeout) stream_set_timeout($fsock, (int)$this->timeout);
            socket_set_nonblock($fsock);
            @socket_connect($fsock, $u['host'], $u['port']);

            $ret = socket_select($fd_read = array($fsock), $fd_write = array($fsock), $except = NULL, $fsock_timeout, 0);
            if ($ret != 1) {
                @socket_close($fsock);
                return false;
            }
            $in = $this->buildHttpHeader($url);
            if (!@socket_write($fsock, $in, strlen($in))) {
                socket_close($fsock);
                return false;
            }
            unset($in);
            socket_set_block($fsock);
            @socket_set_option($fsock, SOL_SOCKET, SO_RCVTIMEO, array("sec" => $fsock_timeout, "usec" => 0));
            $response = '';
            while ($buff = socket_read($fsock, 1024)) {
                $response .= $buff;
            }
            @socket_close($fsock);
        }
        return $response;
    }

    /**
     * 通过curl获取数据
     *
     * @param string $url           要获取的URL
     * @return bool|mixed
     */
    public function curlRequest($url)
    {
        $response = false;
        if (function_exists("curl_init")) {
            $u = parse_url($url);
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if ($u['scheme'] == 'https') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
            }
            if ($this->user_agent) {
                curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
            }
            if ($this->http_version == self::HTTP_VERSION_1_0)
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
            if (!empty($this->proxy))
                curl_setopt($ch, CURLOPT_PROXY, "{$u['host']}:{$u['port']}");
            if ($this->timeout > 0)
                curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->timeout);
            $header_info = $this->header;
            if ($this->post) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->post);
                $header_info[] = 'Expect';
            }
            if ($header_info) curl_setopt($ch, CURLOPT_HTTPHEADER, $header_info);

            $response = curl_exec($ch);
            curl_close($ch);
        }
        return $response;
    }

    /**
     * 通过fsock获取数据
     *
     * @param string $url           要获取的URL
     * @return bool|string
     */
    public function fsockRequest($url)
    {
        $response = false;
        $u = $this->parseUrl($url);
        if ($this->proxy) {
            $u['host'] = $this->proxy['host'];
            $u['port'] = $this->proxy['port'];
        }
        $fp = @fsockopen($u['host'], $u['port'], $null, $null, 1);
        if ($fp) {
            if ($this->timeout) stream_set_timeout($fp, (int)$this->timeout);

            $in = $this->buildHttpHeader($url);
            if (fwrite($fp, $in)) {
                while (!feof($fp)) {
                    $response .= fgets($fp, 1024);
                }
                fclose($fp);
            }
        }
        return $response;
    }

    /**
     * 设置错误Error
     *
     * @param $error
     */
    protected function setError($error)
    {
        $this->error = $error;
    }

    /**
     * 检查URL
     *
     * @param string $url           要获取的URL
     * @return bool
     */
    protected function checkUrl($url)
    {
        $u = self::parseUrl($url);
        if (!$u) $this->setError('URL解析错误');
        if (in_array($u['port'], array('', 0))) $this->setError("访问端口异常{$u['port']}");
        if (!$u['host']) $this->setError("访问主机名异常:{$u['host']}");
        return !$this->error;
    }

    /**
     * 解析URL
     *
     * @param string $url           要获取的URL
     */
    protected static function parseUrl($url)
    {
        static $parses = array();
        if (empty($parses[$url])) {
            $u = parse_url($url);
            switch ($u['scheme']) {
                case 'http':
                    $default_port = '80';
                    break;
                case 'https':
                    $default_port = '443';
                    break;
                case 'ftp':
                    $default_port = '21';
                    break;
                case 'ftps':
                    $default_port = '990';
                    break;
                default:
                    $default_port = 0;
                    break;
            }
            $u['uri'] = (!empty($u['path']) ? $u['path'] : '/') . (!empty($u["query"]) ? "?" . $u ["query"] : "") . (!empty($u ["fragment"]) ? "#" . $u ["fragment"] : "");
            $u['hostname'] = $u['host'] . (!empty($u['port']) ? ":{$u['port']}" : "");
            $u['host'] = @gethostbyname($u ["host"]);
            $u['port'] = !empty($u['port']) ? $u['port'] : $default_port;
            $parses[$url] = $u;
        }
        return $parses[$url];
    }

    /**
     * Decode chunk
     *
     * @param $str
     * @return string
     */
    protected static function decodeChunk($str)
    {
        $body = '';
        while ($str) {
            $chunk_pos = strpos($str, "\r\n") + 2;
            $chunk_size = hexdec(substr($str, 0, $chunk_pos));
            $str = substr($str, $chunk_pos);
            $body .= substr($str, 0, $chunk_size);
        }
        return $body;
    }

    /**
     * 生成HTTP REQUEST HEADER
     *
     * @param string $url           要获取的URL
     * @return string
     */
    protected function buildHttpHeader($url)
    {
        $u = self::parseUrl($url);

        $in = ($this->post ? 'POST ' : 'GET ') . $u['uri'] . " HTTP/" . $this->buildHttpVersion() . "\r\n";
        $in .= "Accept: */*\r\n";
        $in .= 'Host: ' . $u ['hostname'] . "\r\n";
        if ($this->post) {
            //$in .= "Content-type: application/x-www-form-urlencoded\r\n";
            $in .= 'Content-Length: ' . strlen($this->post) . "\r\n";
        }
        if ($this->user_agent) $in .= "User-Agent: {$this->user_agent}\r\n";
        if ($this->header) $in .= join("\r\n", $this->header) . "\r\n";

        $in .= "Connection: Close\r\n\r\n";
        if ($this->post) $in .= $this->post . "\r\n\r\n";
        return $in;
    }

    /**
     * 生成HTTP版本号
     *
     * @return string       协议版本号
     */
    protected function buildHttpVersion()
    {
        if (in_array($this->http_version, array(self::HTTP_VERSION_1_0, self::HTTP_VERSION_1_1))) {
            return $this->http_version;
        }
        return self::HTTP_VERSION_1_1;
    }

}

?>