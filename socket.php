<?php

class Socketsitos {
    private function _socket_server() {
        error_reporting(E_ALL);
        set_time_limit(0);
        ob_implicit_flush();
        extension_loaded('sockets') or die('The sockets extension is not loaded.');

        try {
            $this->_start = time();
            if(!($this->_server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) throw new Exception('Unable to create AF_UNIX socket: '. socket_strerror(socket_last_error()));
            if(!socket_set_option($this->_server, SOL_SOCKET, SO_REUSEADDR, 1)) throw new Exception('Unable to set socket option: '. socket_strerror(socket_last_error($this->_server)));

            if(!socket_bind($this->_server, 0, $this->_port)) throw new Exception('Unable to bind socket: '. socket_strerror(socket_last_error($this->_server)));

            if(!socket_listen($this->_server)) throw new Exception('Unable to listen socket: '. socket_strerror(socket_last_error($this->_server)));

            fwrite(STDOUT, "Socket binded, starting to listen...\n");
            while (true):
                $changed = array_merge([$this->_server], $this->_clients);
                $select = socket_select($changed, $null, $null, $sec = 0);
                if(false === $select) throw new Exception('Unable to select socket: '. socket_strerror(socket_last_error($this->_server)));

                if (in_array($this->_server, $changed)):
                    if(false === ($socket_new = socket_accept($this->_server))) throw new Exception('Unable to accept socket: '. socket_strerror(socket_last_error($this->_server))); //accpet new socket
                    
                    $header = socket_read($socket_new, 1024);
                    $this->_socket_handshake($header, $socket_new);
                    socket_getpeername($socket_new, $ip);

                    /** Register the socket client into the clients array */
                    $this->_socket_register($socket_new, $ip);

                    fwrite(STDOUT, "New connection from {$ip} stablished.\n");
                    fwrite(STDOUT, 'Connected users: '. sizeof($this->_clients)."\n");
                endif;

                if(time() - $this->_start >= 1):
                    $methods = $this->_thick_functions;
                    while(!!($method = array_shift($methods))):
                        $this->{$method}();
                    endwhile;
                    $this->_start = time();
                endif;
            endwhile;
        } catch (Exception $e) {
            fwrite(STDOUT, "Error on socket server: {$e}\n");
        }
    }
    
    private function _socket_register($client, $ip) {
        $pos = '';
        $data = '';
        do {
            if (false === ($buf = socket_read($client, 2048))):
                echo "socket_read() failed: reason: " . socket_strerror(socket_last_error($client)) . "\n";
                break;
            endif;
            $data = json_decode($this->_unmask($buf));
            if($data->action === 'register' and !empty($data->value)):
                $data = $data->value;
                break;
            endif;
        } while(true);
        $pos = md5($ip.$data);
        $this->_clients[$pos] = $client;
        $methods = $this->_onconnect_functions;
        while(!!($method = array_shift($methods))):
            $this->{$method}($pos, $ip, $data);
        endwhile;
        $location = ['action'=>'register', 'pos'=>$pos];
        $this->_socket_send_message(json_encode($location), [$client]);
    }

    private function _socket_send_message($msg, array $target = null) {
        $target = $target ?? $this->_clients;
        $msg = $this->_mask($msg);
        $temp = $target ?? [];
        while(!!($socket = array_shift($temp))):
            if(!socket_write($socket, $msg, strlen($msg))):
                echo 'Removing inactive socket', PHP_EOL;
                if (in_array($socket, $this->_clients)):
                    $pos = array_search($socket, $this->_clients);
                    $this->_clients[$pos] = null;
                    unset($this->_clients[$pos]);
                endif;
                socket_close($socket);
            endif;
        endwhile;
        return true;
    }

    private function _unmask($text) {
        $length = ord($text[1]) & 127;

        switch($length):
            case 126:
                $masks = substr($text, 4, 4);
                $data = substr($text, 8);
            break;
            case 127:
                $masks = substr($text, 10, 4);
                $data = substr($text, 14);
            break;
            default:
                $masks = substr($text, 2, 4);
                $data = substr($text, 6);
            break;
        endswitch;

        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i%4];
        }
        return $text;
    }

    private function _mask($text) {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);

        if($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header.$text;
    }

    private function _socket_handshake($receved_header, $client_conn) {
        $host = $this->_host ?? parse_url(INST_URI, PHP_URL_HOST);
        $port = $this->_port ?? parse_url(INST_URI, PHP_URL_PORT);
        $headers = [];
        $lines = preg_split("/\r\n/", $receved_header);
        while(null !== ($line = array_shift($lines))):
            $line = chop($line);
            if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
                $headers[$matches[1]] = $matches[2];
        endwhile;
        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        //hand shaking header
        $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "WebSocket-Origin: {$host}\r\n" .
        "WebSocket-Location: wss://{$host}:{$port}\r\n".
        "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        socket_write($client_conn,$upgrade,strlen($upgrade));
    }

    private function _hip() {
        fwrite(STDOUT, "Me ejecuto cada segundo\n");
    }

    public function start() {
        $this->_host = '127.0.0.1';
        $this->_port = 9005;
        $this->_server = null;
        $this->_clients = [];


        $this->_thick_functions = ['_hip'];
        $this->_onconnect_functions = [];

        fwrite(STDOUT, "Starting socket...\n");
        $this->_socket_server();
    }
   
}

$sock = new Socketsitos();
$sock->start();
?>