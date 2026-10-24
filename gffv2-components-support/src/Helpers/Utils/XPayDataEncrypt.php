<?php
namespace target\support\Helpers;

/**
 * xpay 加密类
 */
class XPayDataEncrypt
{
    private $str_split = array('', 'g', 'h', 'G', 'k', 'g', 'J', 'K', 'I', 'h', 'i', 'j', 'H');
    private $num = 0;
    private $str_temp = '';
    
    public function encryptData($to_encrypt)
    {
        if ($to_encrypt != '' && $to_encrypt != null) {
            $to_encrypt = utf8_encode($to_encrypt);
            $to_encrypt = str_Replace('\r\n', '', $to_encrypt);
        
            $ary_temp = unpack('C*', $to_encrypt);
        
            $str_temp = implode(',', $ary_temp);
            $ary_temp = explode(',', $str_temp);
            $count = count($ary_temp);
            for ($i = 0; $i < $count; $i++) {
                $number = (int) $ary_temp[$i];
                $this->encryptToInt16($number);
                $str_encrypted_data = $this->encryptToInt16($number);
            
                $this->str_temp = $this->str_temp . $str_encrypted_data;
            }
        }
        
        return $this->str_temp;
    }

    public function encryptToInt16($number)
    {
        if ($this->num == 12) {
            $this->num = 1;
        } else {
            $this->num++;
        }
        $my_char = 'H';
    
        try {
            $my_char = $this->str_split[$this->num];
        } catch (Exception $exception) {
            $this->num = 1;
        }

        $my_char = $this->str_split[$this->num];
        return  strval(dechex($number) . $my_char);
    }

    public function decryptData($to_decrypt)
    {
        $this->str_temp = '';
        if ($to_decrypt != '' && $to_decrypt != null) {
            $to_decrypt = str_replace('\r\n', '', $to_decrypt);
            $to_decrypt = str_replace(' ', ',', $to_decrypt);
            $to_decrypt = str_replace('g', ',', $to_decrypt);
            $to_decrypt = str_replace('G', ',', $to_decrypt);
            $to_decrypt = str_replace('H', ',', $to_decrypt);
            $to_decrypt = str_replace('h', ',', $to_decrypt);
            $to_decrypt = str_replace('K', ',', $to_decrypt);
            $to_decrypt = str_replace('k', ',', $to_decrypt);
            $to_decrypt = str_replace('J', ',', $to_decrypt);
            $to_decrypt = str_replace('j', ',', $to_decrypt);
            $to_decrypt = str_replace('I', ',', $to_decrypt);
            $to_decrypt = str_replace('i', ',', $to_decrypt);
            $to_decrypt = strtoupper($to_decrypt);
            $ary_temp = explode(',', $to_decrypt);

            $count = count($ary_temp);
            for ($i = 0; $i < $count; $i++) {
                if ($ary_temp[$i] != '') {
                    $number = (int) hexdec($ary_temp[$i]);
                    $str_decrypted_data = $this->decryptToString($number);
                    $this->str_temp = $this->str_temp . $str_decrypted_data;
                }
            }
        }
        return $this->str_temp;
    }
    
    public function decryptToString($number)
    {
        return strval(chr($number));
    }
}
