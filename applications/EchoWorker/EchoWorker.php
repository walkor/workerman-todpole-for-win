<?php
/**
 * EchoWorker
 * 
 */

class EchoWorker extends Man\Core\SocketWorker
{
    public function dealInput($recv_str)
    {
        return 0;
    }
   
    public function dealProcess($recv_str)
    {
        var_export($this->sendToClient($recv_str));
    }
}
