<?php
// vim: set et ts=4 sw=4 sts=4:

class Nats
{
    const VERSION = '0.1';

    private $fp         = null;
    private $hosti      = 'localhost';
    private $port       = 4222;
    private $errno      = 0;
    private $errstr     = '';
    private $timeout    = 3;
    private $subscribed = array();
    private $serverInfo = array();
    private $opt        = array();
    private $loop       = false;

    private $pids       = array();

    public function __construct($host = null, $port = null, $opt = array())
    {
        if ($host !== null) {
            $this->host = $host;
        }
        if ($port !== null) {
            $this->port = $port;
        }
        $this->opt = array_merge(
            array(
                'verbose'      => false,
                'pedantic'     => false,
                'ssl_required' => false,
                //'auth_token'   => null,
                //'user'         => null,
                //'pass'         => null,
                'name'         => 'phpnats',
                'lang'         => 'PHP',
                'version'      => self::VERSION,
            ),
            $opt
        );
        $this->init();
    }

    public function __destruct()
    {
        //$this->finish();
    }

    private function addPids($pid)
    {
        $this->pids[] = $pid;
    }

    private function delPids($pid)
    {
        $idx = array_search($pid, $this->pids);
        if ($idx !== false) {
            unset($this->pids[$idx]);
            $this->pids = array_merge($this->pids);
        }
    }

    public function sigchild($pid = null)
    {
        self::debug(__FUNCTION__ . " {$pid} " . str_repeat('-', 30));

        if ($pid === null) {
            foreach ($this->pids as $cpid) {
                $rc = pcntl_waitpid($cpid, $status, WNOHANG);
                $this->delPids($rc);
            }
        } else {
            $this->delPids($pid);
        }
    }

    public function finish()
    {
        foreach ($this->pids as $pid) {
            pcntl_waitpid($pid, $status, WUNTRACED);
        }

        self::debug(__FUNCTION__ . str_repeat('-', 30));
        if (!empty($this->subscribed)) {
            foreach ($this->subscribed as $subject => $ids) {
                foreach ($ids as $sid) {
                    $this->unsubscribe($sid);
                }
            }
        }
        $this->close();
    }

    public function setTimeout($sec)
    {
        $this->timeout = (int)$sec > 0 ? (int)$sec : 5;
    }

    public function stop()
    {
        self::debug(__FUNCTION__ . str_repeat('-', 30));
        $this->loop = false;
    }

    public function init()
    {
        $this->serverInfo = array();
        $this->subscribed = array();
        if ($this->open() !== false) {
            return $this->connect();
        }
        return false;
    }

    private function open()
    {
        $this->fp = fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->timeout);
        if ($this->fp !== false) {
            stream_set_timeout($this->fp, $this->timeout);
            $this->serverInfo = json_decode($this->recv(), true);
        }
        return $this->fp !== false;
    }

    private function close()
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    protected function onMessage($subject, $sid, $message)
    {
        printf("+%s\n| ch: %s\n| to: %s\n| message:\n| %s\n+%s\n",
            str_repeat('-', 50),
            $subject,
            $sid,
            $message,
            str_repeat('-', 50)
        );
    }

    public function recv($c = 1)
    {
        //self::debug(__FUNCTION__ . " {$c} " . str_repeat('-', 30));
        if (is_resource($this->fp)) {
            $res = fgets($this->fp);
            if (is_resource($this->fp)) {
                $smd = stream_get_meta_data($this->fp);
                if (isset($smd['timed_out']) && $smd['timed_out']) {
                    return null;
                }
            }
            if (strpos($res, 'MSG') === 0) {
                $buf = explode(' ', $res);
                $size = array_pop($buf);
                $payload = fread($this->fp, (int)$size);
                fread($this->fp, 2);
                $pid = pcntl_fork();
                if ($pid == -1) {
                    self::debug('fork failed');
                } elseif ($pid) {
                    $this->addPids($pid);
                } else { 
                    $this->onMessage($buf[1], $buf[2], $payload);
                    exit;
                }
                $res = $this->recv($c + 1);
            } else {
                self::debug('<- ' . rtrim($res));
                if (strpos($res, 'PING') === 0) {
                    $this->pong();
                    $res = $this->recv($c + 1);
                } elseif (strpos($res, 'PONG') === 0) {
                    $res = $this->recv($c + 1);
                }
            }
            return $res;
        }
        return false;
    }

    protected function send($data)
    {
        //self::debug(__FUNCTION__ . str_repeat('-', 30));
        if (is_resource($this->fp)) {
            $rc = fwrite($this->fp, $data);
            self::debug('-> ' . rtrim($data));
            return $rc !== false;
        }
        return false;
    }

    protected function sendCmd($data)
    {
        //self::debug(__FUNCTION__ . str_repeat('-', 30));
        if ($this->send($data) !== false) {
            if ($this->opt['verbose']) {
                $res =$this->recv();
                return $res[0] === '+';
            } else {
                return true;
            }
        }
        return false;
    }

    public function connect()
    {
        $cmd = sprintf("CONNECT %s\r\n", json_encode($this->opt));
        $res = $this->sendCmd($cmd);
        return $res;
    }

    public function publish($subject, $message, $replyto = null)
    {
        $cmd = sprintf("PUB %s%s %d\r\n%s\r\n",
            $subject,
            $replyto === null ? '' : " {$replyto}",
            strlen($message),
            $message
        );
        return $this->sendCmd($cmd);
    }

    private function makeSid()
    {
        return md5(sprintf('%d%d', time(), rand(0, time())));
    }

    public function subscribe($subject, $group = null, $sid = null)
    {
        $subid = $sid !== null ? $sid : $this->makeSid();
        $cmd = sprintf("SUB %s%s %s\r\n",
            $subject,
            $group === null ? '' : " {$group}",
            $subid
        );
        $res = $this->sendCmd($cmd);
        if ($res) {
            $this->subscribed[$subject][] = $subid;
        }
        return $res;
    }

    public function unsubscribe($sid, $maxmsgs = null)
    {
        $cmd = sprintf("UNSUB %s%s\r\n",
            $sid,
            $maxmsgs === null ? '' : " {$maxmsgs}"
        );
        return $this->sendCmd($cmd);
    }

    public function ping()
    {
        return $this->send("PING\r\n");
    }

    public function pong()
    {
        return $this->send("PONG\r\n");
    }

    public function wait()
    {
        $this->loop = true;
        //self::debug(__FUNCTION__ . str_repeat('-', 30));
        while (($buf = $this->recv()) === null) {
            if (!$this->loop) {
                self::debug("!!! stop !!!\n");
                break;
            }
            if ($buf[1] === '-') {
                break;
            }
        }
        self::debug(__FUNCTION__ . str_repeat('-', 30));
    }

    protected static function debug($s)
    {
        echo $s, "\n";
    }
}
