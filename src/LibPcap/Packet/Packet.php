<?php
/**
 * Created by PhpStorm.
 * User: tricci
 * Date: 28.10.2015
 * Time: 08:31
 */

namespace LibPcap\Packet;


use LibPcap\Buffer;
use LibPcap\File\Encoding;
use LibPcap\Packet\Protocols\NoProtocol;

class Packet
{
    protected $_attributes;
    protected $buffer;
    protected $index;
    protected $isMalphormed;
    /**
     * Raw head data
     * @var string
     */
    protected $headData;
    /**
     * Parsed head Data
     * @var array
     */
    protected $head;
    protected $protocols;

    protected $_protocols=array(
        "Ethernet",
        "Ip",
        array("Tcp","Udp"),
        array("Dhcp","Http")
    );
    public $defaultProtocolNamespace = "\\LibPcap\\Packet\\Protocols\\";

    public function __construct(Buffer $buffer = null,Encoding $encoding = null)
    {
        $this->encoding=$encoding;
        $this->buffer=$buffer;
        $this->headData=$buffer->get(16); #head data is 16 bytes
        $this->_attributes=array();
        $this->isMalphormed=true;

        $this->head = $this->decodeHeadFromPacket($this->headData);
    }

    public function __get($val)
    {
        if(isset($this->{$val}))
            return $this->{$val};
        return $this->_attributes?:null;
    }

    public function addProtocol($classPath,$level)
    {
        if(!class_exists($this->defaultProtocolNamespace.$classPath))
        $this->_protocols[$level][]=$classPath;
        return $this;
    }

    public function removeProtocol($className,$level)
    {
        foreach ($this->_protocols[$level] as $i=>$p) {
            if($p == $className)
                unset($this->_protocols[$level][$i]);
        }
        return $this;

    }
    public function isCorrect()
    {
        return !$this->isMalphormed;
    }
    public function fill($data)
    {
      //TODO: validate data
      return $this->fillDirty($data);
    }
    protected function fillDirty($data)
    {
        $this->_attributes=array_merge($this->_attributes,$data);
        return $this;
    }
    public function getHead()
    {
        return $this->head;
    }
    public function getEthernet()
    {
      return $this->protocols[1];
    }
    public function getIp()
    {
      return $this->protocols[2];
    }
    public function getTransport()
    {
        return $this->protocols[3];
    }
    public function getProtocol()
    {
        if(isset($this->protocols[4]) && !$this->protocols[4] instanceof NoProtocol)
            return $this->protocols[4];
        else
            return $this->protocols[3];
    }
    public function getEncoding()
    {
      return $this->encoding;
    }
    public function decode()
    {
        $buffer = $this->buffer;

        if($this->isMalphormed)
          return $this;

        $head = $this->getHead();
        $head["data"] = $buffer->get($head['incl_len']);
        $this->setHead($head);
        //propagate the decoding to the different layers in the tcp layer
        foreach($this->_protocols as $tcpLayer => $protocols)
        {

          $head = $this->getHead();
          if(is_array($protocols))
          {
              foreach($protocols as $protocolName)
              {
                $protocol = $this->decodeProtocol($protocolName,$head);
                $head = $protocol->getAttributes();

                if($protocol->isValid())
                  $this->protocols[$tcpLayer] = $protocol;
              }
          }
          else
          {
            $protocolName = $protocols;
            $protocol = $this->decodeProtocol($protocolName);
            $head = $protocol->getAttributes();//we reassign the head data to allow decoding of more in depth protocols

            if($protocol->isValid())
              $this->protocols[$tcpLayer] = $protocol;
          }
            //we may not have decoded any protocol, so we just
          if(!isset($this->protocols[$tcpLayer])){
            $this->protocols[$tcpLayer] = $this->decodeProtocol("NoProtocol");
          }
          $this->setHead($head);
        }
        return $this;
    }
    protected function decodeHeadFromPacket($data)
    {
        $enc=$this->encoding;
        $head = @unpack($enc->u32."ts_sec/".
            $enc->u32."ts_usec/".
            $enc->u32."incl_len/".
            $enc->u32."orig_len/",
            $data);
        if ($head['incl_len'] > $head['orig_len'] || $head['incl_len'] > $this->header['snaplen']) {
            $this->isMalphormed=true;
        }
        if ($head['incl_len'] == 0) {//just return false, so the loops end if we cannot iterate over that packet
            $this->isMalphormed=true;
            return false;
        }
        $this->isMalphormed=false;
        return $head;
    }

  /**
   * @param array $head
   * @return Packet
   */
  public function setHead($head)
  {
    $this->head = $head;
    return $this;
  }

  protected function decodeIpFrame($data)
    {
        $x = unpack("Cversion_ihl/Cservices/nlength/nidentification/nflags_offset/Cttl/Cprotocol/nchecksum/Nsource/Ndestination", $data);
        $x['version'] = $x['version_ihl'] >> 4;
        $x['ihl'] = $x['version_ihl'] & 0xf;
        unset($x['version_ihl']);
        $x['flags'] = $x['flags_offset'] >> 13;
        $x['offset'] = $x['flags_offset'] & 0x1fff;
        $x['source_ip'] = long2ip($x['source']);
        $x['destination_ip'] = long2ip($x['destination']);
        $x['data'] = substr($data,$x['ihl']*4,$x['length']-$x['ihl']*4); // ignoring options
        $this->isMalphormed=false;
        return $x;
    }
    protected function decodeProtocol($protocolClass,$data = array())
    {
      if(class_exists($this->defaultProtocolNamespace.$protocolClass)){
          $cls = $this->defaultProtocolNamespace.$protocolClass;
          $protocol = new $cls($data,$this->getEncoding());
      }
      elseif(class_exists($protocolClass))
          $protocol = new $protocolClass($data,$this->getEncoding());
      else
        throw new \Exception("The class {$protocolClass} couldn't be found");

      $res = $protocol->decode();
      $protocol->setValid($res);

      return $protocol;

    }
    protected function decodeEthernetFrame($data)
    {
        $x = unpack("nethertype", substr($data,12,2));
        $x['destination_mac'] = bin2hex(substr($data,0,6));
        $x['source_mac'] = bin2hex(substr($data,6,6));
        $x['data'] = substr($data,14);
        return $x;
    }

    public function encode()
    {

    }
}
