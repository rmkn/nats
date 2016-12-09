<?php
// vim: set et ts=4 sw=4 sts=4:

require_once 'Nats.php';

declare(ticks = 1);

function sig_handler($signo)
{
    global $nc;
    $nc->stop();
    $nc->finish();
}

function sigchild_handler($signo)
{
    global $nc;
    $nc->sigchild();
}

pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGCHLD, "sigchild_handler");

class LaLogger extends Nats
{
    protected function onMessage($subject, $sid, $message)
    {
        self::debug(var_export($message, true));
    }
}

$nc = new LaLogger('localhost');
$nc->subscribe('subject');
$nc->wait();