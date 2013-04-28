<?php
namespace Orpheus;

class PHPSmallify {
    protected $reserved_variables = array('_GET', '_POST', '_COOKIE', '_SESSION', '_SERVER', 'GLOBALS', '_FILES', '_REQUEST', '_ENV', 'php_errormsg', 'HTTP_RAW_POST_DATA', 'http_response_header', 'argv', 'argc', 'this');
    
    protected $reserved_methods = array('__construct', '__destruct', '__call', '__callStatic', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup', '__toString', '__invoke', '__set_state', '__clone');
    
    protected $php_code = null, $new_php_code = null, $php_code_size;
    protected $variables = array(), $functions = array();
    
    public function __construct($file = null, $code = null) {
        if ($file !== null && $code === null) {
            $this->loadFile($file);
        } else if ($code !== null) {
            $this->loadPHPCode($code);
        }
    }
    
    public function loadPHPCode($code) {
        $filename = __DIR__ . '/tmp/' . md5($code) . '.tmp.php';
        file_put_contents($filename, $code);
        chmod($filename, 0777);
        if ($this->php_check_syntax($filename, $errors)) {
            $this->php_code = $code;
        } else {
            throw new \Exception(__METHOD__ . ': The PHP contains syntax errors.');
        }
        unlink($filename);
    }
    
    public function loadFile($file) {
        if (is_file($file)) {
            if ($this->php_check_syntax($file, $errors) == false) {
                throw new \Exception(__METHOD__ . ': "' . $file . '" contains syntax errors: ' . $errors[0]);
            }
            
            $this->php_code = file_get_contents($file);
            $this->php_code_size = strlen($this->php_code);
        } else {
            throw new \Exception(__METHOD__ . ': "' . $file . '" does not exist.');
        }
    }
    
    public function validPHP($in) {
        return preg_match('/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/', $in);
    }
    
    public function smallify($stripComments = true, $encodeStrings = false, $stripWhiteSpace = true, $shrinkFunctions = false) {
        if ($this->php_code == null) {
            throw new \Exception(__METHOD__ . ': Need to load PHP code first.');
        }
        $this->php_code = mb_convert_encoding($this->php_code, 'UTF-8');
        
        $tokens = token_get_all($this->php_code);
        $this->new_php_code = null;
        
        $chars = range('a', 'z');
        $countChars = count($chars);
        $usedVariables = array();
        $replacedVariables = array();
        $usedFunctions = array();
        $replacedFunction = array();
        $i = 0;
        foreach ($tokens as $key => $token) {
            if (!is_array($token)) {
                $this->new_php_code .= $token;
                continue;
            }
            if (($token[0] == T_VARIABLE || (isset($tokens[$key - 2]) && $tokens[$key - 2][0] == T_VARIABLE && $tokens[$key - 2][1] == '$this' && isset($tokens[$key - 1]) && $tokens[$key - 1][0] = T_OBJECT_OPERATOR && $tokens[$key + 1] != '(')) && !in_array(substr($token[1], 1), $this->reserved_variables)) {
                if ((isset($tokens[$key - 2]) && $tokens[$key - 2][0] == T_VARIABLE) && (isset($tokens[$key - 1]) && $tokens[$key - 1][0] == T_OBJECT_OPERATOR)) {
                    $prefix = '';
                } else {
                    $prefix = '$';
                }
                
                if (substr($token[1], 0, 1) == '$') {
                    $token[1] = substr($token[1], 1);
                }
                if (isset($replacedVariables[$token[1]])) {
                    $token[1] = $prefix . $replacedVariables[$token[1]];
                } else {
                    $oldVariable = $token[1];
                    $token[1] = $chars[$i];
                    while (in_array($token[1], $usedVariables)) {
                        $token[1] .= $chars[$i];
                    }
                    $usedVariables[] = $token[1];
                    $replacedVariables[$oldVariable] = $token[1];
                    $token[1] = $prefix . $token[1];
                    $i++;
                }
            }
            
            if ($shrinkFunctions) {
                if ($token[0] == T_STRING) {
                    if (isset($tokens[$key - 2]) && $tokens[$key - 2][0] == T_FUNCTION) {
                        if (!in_array($token[1], $this->reserved_methods)) {
                            if (isset($replacedFunctions[$token[1]])) {
                                $token[1] = $replacedFunctions[$token[1]];
                            } else {
                                $oldFunction = $token[1];
                                $token[1] = $chars[$i];
                                while (in_array($token[1], $usedFunctions)) {
                                    $token[1] .= $chars[$i];
                                }
                                $usedFunctions[] = $token[1];
                                $replacedFunctions[$oldFunction] = $token[1];
                                $i++;
                            }
                        }
                    }
                }
            }
            
            if ($stripComments && $token[0] == T_COMMENT || $token[0] == T_DOC_COMMENT) {
                continue;
            }
            
            if ($stripWhiteSpace && $token[0] == T_WHITESPACE) {
                if (isset($tokens[$key - 1]) && isset($tokens[$key + 1]) && is_array($tokens[$key - 1]) && is_array($tokens[$key + 1]) && $this->validPHP($tokens[$key - 1][1]) && $this->validPHP($tokens[$key + 1][1])) {
                    $this->new_php_code .= ' ';
                }
                continue;
            }
            
            if ($encodeStrings && $token[0] == T_CONSTANT_ENCAPSED_STRING) {
                if (isset($tokens[$key - 6]) && !in_array($tokens[$key - 6][0], array(
                    T_PROTECTED,
                    T_PRIVATE,
                    T_PUBLIC,
                    T_VAR
                ))) {
                    $token[1] = 'str_rot13(base64_decode(strrev("' . strrev(base64_encode(str_rot13(substr($token[1], 1, -1)))) . '")))';
                }
            }
            
            $this->new_php_code .= $token[1];
            if ($i >= $countChars - 1) {
                $i = 0;
            }
        }
        
        if ($shrinkFunctions) {
            $newTokens = token_get_all($this->new_php_code);
            $new = '';
            foreach ($newTokens as $i => $token) {
                if (!is_array($token)) {
                    $new .= $token;
                } else {
                    if ($token[0] == T_STRING) {
                        if ($newTokens[$i - 2][0] != T_FUNCTION && isset($newTokens[$i + 1]) && $newTokens[$i + 1] == '(') {
                            if (isset($replacedFunctions[$token[1]])) {
                                $token[1] = $replacedFunctions[$token[1]];
                            }
                        }
                    }
                    
                    $new .= $token[1];
                }
            }
            $this->new_php_code = $new;
        }
        
        $compression_ratio = strlen($this->new_php_code) / $this->php_code_size;
        $space_savings = 1 - (strlen($this->new_php_code) / $this->php_code_size);
        
        $filename = __DIR__ . '/' . md5($this->new_php_code) . '.tmp.php';
        file_put_contents($filename, $this->new_php_code);
        chmod($filename, 0777);
        $valid = $this->php_check_syntax($filename, $errors);
        if (!$valid) {
            var_dump($errors);
            throw new \Exception(__METHOD__ . ': The minified PHP code contains errors. Please notify the developer.');
        }
        unlink($filename);
        
        return array(
            'smallified' => $this->new_php_code,
            'initial_size' => $this->php_code_size,
            'new_size' => strlen($this->new_php_code),
            'compression_ratio' => $compression_ratio,
            'space_savings' => $space_savings * 100
        );
    }
    
    public function php_check_syntax($file, &$errors) {
        $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
        $output = exec($cmd, $op, $ret_val);
        if (preg_match('/no syntax errors/i', $output)) {
            return true;
        } else {
            reset($op);
            end($op);
            $lastKey = key($op);
            unset($op[$lastKey]);
            reset($op);
            
            $errors = $op;
            
            return false;
        }
    }
}