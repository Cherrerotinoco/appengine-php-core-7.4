<?php

/*
 * https://www.notion.so/cloudframework/TEST-JSON-FILE-d13852c566084eaaa4914b6723319f67
 */
class Script extends Scripts2020
{

    var $organization = null;
    var $_environment = null;
    var $testId = null;
    var $test = [];
    var $run_vars = [];
    var $user_email = '';
    var $user_token = '';
    var $report = [];
    var $debug = false;

    function main()
    {

        $this->sendTerminal('CloudFrameworkTest v73.1002');
        $this->sendTerminal('  -  more info in: https://www.notion.so/cloudframework/Using-CloudframeworkTest-APIs-b1808be7b8c5454ba585bc64592ccff6'."\n");
        $method = (isset($this->params[1])) ? $this->params[1] : 'default';
        if (!is_dir('./local_data')) mkdir('./local_data');
        if (!is_dir('./local_data/cache')) mkdir('./local_data/cache');
        if(!$this->core->config->get("core.cache.cache_path")) $this->core->config->set("core.cache.cache_path","{{rootPath}}/local_data/cache");
        $this->cache->debug = false;

        if($this->hasOption('debug')) $this->debug = true;

        // $this->core->cache->debug = false;
        //Call internal ENDPOINT_{$this->params[1]}
        $method = str_replace('-', '_', $method);
        if (!$this->useFunction('METHOD_' . $method)) {
            return ($this->setErrorFromCodelib('params-error', "/{$method} is not implemented"));
        }

        // Clean-up logs when everything is OK.
        if (!$this->error) $this->core->logs->data = null;
        $this->core->errors->data = null;
        $this->sendTerminal('');
        if(!$this->hasOption('no-finish-prompt'))
            $this->prompt('Enter to finish');
    }

    /**
     * Default method for information
     */
    public function METHOD_default()
    {
        $this->sendTerminal('Available methods:');
        $this->sendTerminal(' - _test/erp/{your_org}  (to start erp tests)');
        $this->sendTerminal(' - _test/clean  (clean local cache)');
    }

    /**
     * Default method for information
     */
    public function METHOD_clean()
    {
        $this->sendTerminal('Cleaning cache data: rm ./local_data/cache/*');
        exec('rm ./local_data/cache/*');
    }

    /**
     * Bnext test
     */
    public function METHOD_erp() {


        //region SET $area
        if(!isset($this->params[2]) || !$this->params[2]) {
            $this->params[2] = $this->prompt('Write the name of your organization _test/erp/');
        }
        $org = $this->params[2];
        $this->organization = $org;
        //endregion

        if(!$this->debug)
            $this->core->request->sendSysLogs = false;
        if(!$this->checkCloudFrameworkUserCredentials($org)) return;

        if(!($tests = $this->loadTest($org))) return;
        $this->run_vars = ($this->cache->get('CloudFramework_test_run_vars_'.$org))?:[];

        //region SET $area
        if(!isset($this->params[3]) || !$this->params[3]) {
            $this->sendTerminal('You can Use:');
            foreach ($tests as $key) if($key[0]!='_') {
                $this->sendTerminal('  - _test/erp/'.$org.'/'.urlencode($key));
            }
            $this->params[3] = $this->prompt('Select area to test _test/erp/'.$org.'/');
        }
        if(!($test = $this->loadTest($org,$this->params[3]))) return;

        $this->testId = $this->params[3];

        $this->sendTerminal('Running tests in: '.$this->testId);
        $this->sendTerminal(' - Test updated by: '.$test['CloudFrameworkUser']);
        $this->sendTerminal('   "'.$test['Description'].'"');
        $this->sendTerminal();
        $this->test = $test['JSON'];

        //region set $test
        if(!isset($this->params[4]) || !$this->params[4]) {
            $this->sendTerminal('You can Use:');
            foreach ($this->test as $key=>$foo) if($key[0]!='_') {
                $this->sendTerminal('  - _test/erp/'.$org.'/'.$this->params[3].'/'.$key);
            }
            $this->params[4] = $this->prompt('Select area to test _test/erp/'.$org.'/'.$this->params[3].'/');
        }
        $area = $this->params[4];
        if(!isset($this->test[$area])) return($this->sendTerminal($this->params[3].'/'.$area.' does not exist in '.$org));
        //endregion

        $time = microtime(true);
        $times = ($this->getOptionVar('repeat'))?intval($this->getOptionVar('repeat')):1;
        for($i=1;$i<=$times;$i++) {
            $this->sendTerminal("\n{$i}/{$times} Executing ".$this->testId.'/'.$area);
            $this->sendTerminal("------------------------------------------------------");
            $this->runTest($area);
        }
        $time = microtime(true)-$time;
        $this->sendTerminal("------------------------------------------------------\nTOTAL TIME TO RUN SCRIPT: ".round($time,4));

    }

    /**
     * Check a token with CloudFramework ERP platform
     * @param $token
     * @return bool[]|false[]|mixed|string[]|null
     */
    private function checkToken($token,$organization) {
        $url = "https://api.cloudframework.io/core/signin/{$organization}/check";
        $user_data = $this->core->request->get_json_decode($url,null,['X-WEB-KEY'=>'Production','X-DS-TOKEN'=>$token]);
        if($this->core->request->error) {
            $this->sendTerminal('Current CF token does not work or has expired.');
            $token=null;
            $this->core->request->error=false;
            $this->core->request->errorMsg=[];
            return null;
        }
        $this->cache_secret_key = md5(json_encode($user_data));
        $this->cache_secret_iv = $user_data['data']['User']['UserEmail'];
        $this->user_email = $user_data['data']['User']['UserEmail'];
        $this->user_token = $token;
        $this->sendTerminal(' - user confirmed: '.$this->user_email."\n");
        return($user_data);
    }

    /**
     * Generate a new token
     * @return bool[]|false[]|mixed|string[]|null
     */
    private function generateCloudFrameworkToken() {
        $this->sendTerminal('Sign in ERP/Backoffice '.$this->params[2]);
        $user = $this->promptVar(['title'=>' - Give me your user','cache_var'=>'user']);
        $password = $this->promptVar(['title'=>' - Give me your password','type'=>'password']);

        $url = "https://api.cloudframework.io/core/signin/{$this->params[2]}/in";
        $params = ['user'=>$user,'password'=>$password,'type'=>'userpassword'];
        $user_data = $this->core->request->post_json_decode($url,$params,['X-WEB-KEY'=>'Production']);
        if($this->core->request->error) {
            $this->core->request->error=false;
            $this->core->request->errorMsg=[];
            return($this->sendTerminal(' Error user/password'));
        }
        $token = $user_data['data']['dstoken'];
        $this->cache->set('token',$token);
        $this->sendTerminal(' - new token generated');
        return($token);
    }

    /**
     * Check if there are right credentials to run this test script
     */
    private function checkCloudFrameworkUserCredentials($organization) {

        $this->sendTerminal('Verifying user credentials in CloudFramework ERP');
        // Get Cloudframework token
        $token = $this->cache->get('token');
        $user_data = [];
        if($token && !($user_data = $this->checkToken($token,$organization))) $token = null;

        if(!$token) {
            if(!($token = $this->generateCloudFrameworkToken())) return;
            if(!($user_data = $this->checkToken($token,$organization))) return;
        }

        return true;
    }

    /**
     * Execute a test previously readed
     * @param $testModule
     */
    private function runTest($testModule) {


        if(!isset($this->test[$testModule])) return($this->addError($testModule.' does not exist'));

        // Read _environment
        if($this->hasOption('default-values')) {
            $value = $this->getCacheVar("_environment");
            if(!$value) {
                $this->test[$testModule]['vars']['_environment']['value']  = $this->promptVar(['title'=>'_environment','default'=>'Stage','cache_var'=>"_environment",'allowed_values'=>['Local','Stage','Production']]);
            } else {
                $this->sendTerminal(' - _environment='.$value);
                $this->test[$testModule]['vars']['_environment']['value'] = $value;
            }
        } else {
            $this->test[$testModule]['vars']['_environment']['value']  = $this->promptVar(['title'=>'_environment','default'=>'Stage','cache_var'=>"_environment",'allowed_values'=>['Local','Stage','Production']]);
        }

        $this->_environment = $this->test[$testModule]['vars']['_environment']['value'];
        $this->test[$testModule]['vars']['_token']['value']  = $this->cache->get('token');

        //region Evaluate prompt variables: "prompt_vars": { .. }
        if(isset($this->test['_default']['prompt_vars']) && is_array($this->test['_default']['prompt_vars'])) {
            foreach ($this->test['_default']['prompt_vars'] as $prompt=> $prompt_description) {
                // extract mandatory values $allowed_values
                $allowed_values = (isset($prompt_description['values']) && $prompt_description['values'] && is_array($prompt_description['values']))?$prompt_description['values']:[];

                // Assign title with default value
                $title = ((isset($prompt_description['title']))?$prompt_description['title']:$prompt);
                $default_value = (isset($prompt_description['defaultvalue']))?$prompt_description['defaultvalue']:'';
                $type = (isset($prompt_description['type']))?$prompt_description['type']:'any';

                if($this->hasOption('default-values')) {
                    $value = $this->getCacheVar("{$prompt}");
                    if(!$value) $value = $default_value;
                    if($value) {
                        $this->sendTerminal(' - '.$title.'='.$value);
                        $this->test[$testModule]['vars'][$prompt]['value'] = $value;
                    }
                    else
                        $this->test[$testModule]['vars'][$prompt]['value']  = $this->promptVar(['title'=>$title,'default'=>$default_value,'cache_var'=>"{$prompt}",'allowed_values'=>$allowed_values,'type'=>$type]);
                } else {
                    $this->test[$testModule]['vars'][$prompt]['value']  = $this->promptVar(['title'=>$title,'default'=>$default_value,'cache_var'=>"{$prompt}",'allowed_values'=>$allowed_values,'type'=>$type]);
                }


            }
        }

        if(isset($this->test[$testModule]['prompt_vars']) && is_array($this->test[$testModule]['prompt_vars'])) {
            foreach ($this->test[$testModule]['prompt_vars'] as $prompt=> $prompt_description) {
                // extract mandatory values $allowed_values
                $allowed_values = (isset($prompt_description['values']) && $prompt_description['values'] && is_array($prompt_description['values']))?$prompt_description['values']:[];

                // Assign title with default value
                $title = ((isset($prompt_description['title']))?$prompt_description['title']:$prompt);
                $default_value = (isset($prompt_description['defaultvalue']))?$prompt_description['defaultvalue']:'';
                $type = (isset($prompt_description['type']))?$prompt_description['type']:'any';

                if($this->hasOption('default-values')) {
                    $value = $this->getCacheVar("{$prompt}");
                    if(!$value) $value = $default_value;
                    if($value) {
                        $this->sendTerminal(' - '.$title.'='.$value);
                        $this->test[$testModule]['vars'][$prompt]['value'] = $value;
                    }
                    else
                        $this->test[$testModule]['vars'][$prompt]['value']  = $this->promptVar(['title'=>$title,'default'=>$default_value,'cache_var'=>"{$prompt}",'allowed_values'=>$allowed_values,'type'=>$type]);
                } else {
                    $this->test[$testModule]['vars'][$prompt]['value']  = $this->promptVar(['title'=>$title,'default'=>$default_value,'cache_var'=>"{$prompt}",'allowed_values'=>$allowed_values,'type'=>$type]);
                }

            }
        }
        //endregion

        //region Merge _default vars with test vars: "_default": { "vars": { .. } }"
        if(!isset($this->test['_default']['vars']) || !is_array($this->test['_default']['vars'])) $this->test['_default']['vars'] = [];
        if(!isset($this->test[$testModule]['vars']) || !is_array($this->test[$testModule]['vars'])) $this->test[$testModule]['vars'] = [];

        $this->test[$testModule]['vars'] = array_merge($this->test['_default']['vars'],$this->test[$testModule]['vars']);
        $this->test[$testModule]['vars'] = array_merge($this->test[$testModule]['vars'],$this->run_vars);
        if(isset($this->test[$testModule]['vars'])) {
            $vars_txt = json_encode($this->test[$testModule]['vars']);
            if(strpos($vars_txt,'{{') && strpos($vars_txt,'}}')) {
                foreach ($this->test[$testModule]['vars'] as $var=> $content) if(strpos($vars_txt,'{{'.$var)) {
                    if(isset($this->test[$testModule]['vars'][$var]['value']))
                        $vars_txt = str_replace('{{'.$var.'}}',$this->test[$testModule]['vars'][$var]['value'],$vars_txt);
                }
                $this->test[$testModule]['vars'] = json_decode($vars_txt,true);
            }
        }
        //endregion

        $report = [];
        //region execute "calls": [ {..},{..} ]
        if(isset($this->test[$testModule]['calls']) && is_array($this->test[$testModule]['calls'])) {
            foreach ($this->test[$testModule]['calls'] as $i=> $content) {

                // init report
                $report = [];
                $error = false;

                //region verify mandatory fields: $content['url']
                if(!isset($content['url']) || !$content['url']) {
                    $this->sendTerminal('ERROR. Missing url in call: '.$i);
                    continue;
                }
                //endregion

                //region apply {{var}} substitutions in $content
                $content_txt = json_encode($content);
                if(strpos($content_txt,'{{') && strpos($content_txt,'}}')) {
                    foreach ($this->test[$testModule]['vars'] as $var=> $var_content) if(strpos($content_txt,'{{'.$var)) {
                        if(isset($this->test[$testModule]['vars'][$var]['value']))
                            $content_txt = str_replace('{{'.$var.'}}',$this->test[$testModule]['vars'][$var]['value'],$content_txt);
                    }
                    $content = json_decode($content_txt,true);
                }
                //endregion

                //region SET: $url,$payload,$method,$headers
                $url = $content['url'];
                $title = (isset($content['title']) && $content['title'])? $content['title']:null;
                $payload = (isset($content['payload']) && $content['payload'])? $content['payload']:null;
                $method = (isset($content['method']) && in_array(strtoupper($content['method']),['GET','POST','PUT','PATCH']))?strtoupper($content['method']):'GET';
                $headers = (isset($content['headers']) && is_array($content['headers']))?$content['headers']:null;
                $stop_on_error = (isset($content['stop-on-error']) && $content['stop-on-error']===true)?true:false;
                //endregion

                //region GET,POST,PUT..: $url.
                $this->sendTerminal();
                $this->sendTerminal("{$testModule} {$title}");

                $time = microtime(true);
                $ret = $this->core->request->call($url,$payload, $method,$headers,true);
                $time = microtime(true)-$time;
                $this->sendTerminal(' - Execution time '.round($time,4).' ms');
                // prepare report
                $report = ['method'=>$method,'url'=>$url,'payload'=>$payload,'headers'=>$headers];
                if(isset($report['payload']['password'])) $report['payload']['password'] = '********';
                if(isset($report['payload']['passw'])) $report['payload']['password'] = '********';

                if(!$this->debug)
                    $this->core->request->sendSysLogs = true;
                $report ['status'] = $this->core->request->getLastResponseCode();
                if(!$this->debug)
                    $this->core->request->sendSysLogs = false;
                if($this->core->request->error) {
                    $this->sendTerminal(' - RESPONSE NOT OK '.$this->core->request->getLastResponseCode());
                    $this->sendTerminal('   payload => '.json_encode($payload));
                    $this->sendTerminal('   result => '.$ret);
                    $report['error'] = $this->core->request->errorMsg;
                    $error = true;
                    if($stop_on_error) {
                        $this->sendReport($this->testId,$testModule,$title,'ERROR',"[{$method}] {$url}",$time,['ERROR_'.$i=>$report]);
                        return;
                    }

                } else {
                    $report['result'] = json_decode($ret,true);
                    $this->sendTerminal(' - OK '.$this->core->request->getLastResponseCode());
                }
                //TODO: send report to CloudFramework.
                //endregion

                //region EVALUATE status
                if(isset($content['status']) && $content['status']) {
                    if($this->core->request->getLastResponseCode() == $content['status']) {
                        $report['status_'.$content['status']] = 'OK';
                        $this->sendTerminal(' - OK Status is '.$content['status']);
                    } else {
                        $this->sendTerminal(' - ERROR Status is not '.$content['status']);
                        $report['status_'.$content['status']] = 'ERROR';
                        $error = true;
                        if($stop_on_error) {
                            $this->sendReport($this->testId,$testModule,$title,'ERROR',"[{$method}] {$url}",$time,['ERROR_'.$i=>$report]);
                            return;
                        }
                    }
                }
                //endregion

                //region EVALUATE check-json-values
                if(isset($content['check-json-values']) && is_array($content['check-json-values'])) {
                    $ret_array = $this->core->jsonDecode($ret);

                    foreach ($content['check-json-values'] as $check_var=>$check_var_content) {
                        $array_path = explode('.',$check_var);

                        $pointer = $ret_array;
                        foreach ($array_path as $item) {
                            if(isset($pointer[$item])) {
                                $pointer = &$pointer[$item];
                            }
                            else {
                                $pointer = null;
                                $report['check_var_'.$check_var] = 'ERROR';
                                $this->sendTerminal(' - ERROR returned structure. Does not exist: '.$check_var.' => '.json_encode($check_var_content));
                                $error = true;
                                break;
                            }
                        }
                        if($pointer) {
                            $report['check_var_'.$check_var] = 'OK';
                            $this->sendTerminal(' - OK JSON Var exist: '.$check_var.'=>'.json_encode($check_var_content));
                            if(isset($check_var_content['equals'])) {
                                if($check_var_content['equals'] == $pointer) {
                                    $this->sendTerminal(' - OK Value equals to: '.$check_var_content['equals']);
                                } else {
                                    $report['check_var_'.$check_var] = 'ERROR';
                                    $error = true;
                                }
                            }
                        }
                        unset($pointer);
                    }
                }
                //endregion

                //region EVALUATE set-run-vars
                if(isset($content['set-run-vars']) && is_array($content['set-run-vars'])) {
                    $ret_array = $this->core->jsonDecode($ret);

                    foreach ($content['set-run-vars'] as $check_var=>$check_var_content) {
                        $array_path = explode('.',$check_var_content);

                        $pointer = &$ret_array;
                        foreach ($array_path as $item) {
                            if(isset($pointer[$item])) {
                                $pointer = &$pointer[$item];
                            }
                            else {
                                $report['set_run_var_'.$check_var] = 'ERROR';
                                $report['set_run_var_'.$check_var.'_content'] = $check_var_content;
                                $error = true;
                                $pointer = null;
                                $this->sendTerminal('  ERROR returned structure. It can not create set-run-var: '.$check_var.'=>'.$check_var_content);
                            }
                        }
                        if($pointer) {
                            $this->run_vars[$check_var] = ['value'=>$pointer];
                            $this->cache->set('CloudFramework_test_run_vars_'.$this->params[1], $this->run_vars);
                            $report['set_run_var_'.$check_var] = 'OK';
                            $this->sendTerminal(' - OK Updated set-run-var "'.$check_var.'" <- {'.$check_var_content.'}: '.$pointer);
                        }
                        unset($pointer);
                    }
                    $this->test[$testModule]['vars'] = array_merge($this->test[$testModule]['vars'],$this->run_vars);
                    $this->sendReport($this->testId,$testModule,$title,($error)?'ERROR':'OK',"[{$method}] {$url}",$time,[(($error)?'ERROR':'OK').'_'.$i=>$report]);

                }
                //endregion

            }
        }
        //endregion
    }

    /**
     * Send a report to CloudFrameWorkTestsLogs
     */
    private function sendReport($testId,$testModule,$title,$status,$url,$time,$json) {

        $cfurl = "https://api.cloudframework.io/core/tests/{$this->organization}/{$testId}";
        $data = [
             'Environment'=>$this->_environment
            ,'TestModule'=>$testModule
            ,'TestTitle'=>$title
            ,'Status'=>$status
            ,'Time'=>round(floatval($time),4)
            ,'Url'=>$url
            ,'Status'=>$status
            ,'JSON'=>$json
        ];
        $report = $this->core->request->post_json_decode($cfurl,$data,['X-WEB-KEY'=>'Production','X-DS-TOKEN'=>$this->user_token]);
        if($this->core->request->error) {
            $this->sendTerminal('Error sending Logs');
            $this->sendTerminal($this->core->request->errorMsg);
            $this->core->request->error=false;
            $this->core->request->errorMsg=[];
            return null;
        }
        return true;
    }

    /**
     * Load JSON test file description
     * @param $test
     * @return bool|void
     */
    private function loadTest($org,$test=null) {

        $url = 'https://api.cloudframework.io/core/tests/'.$org;
        if($test) $url.='/'.$test;

        $ret = $this->core->request->get_json_decode($url,null,['X-API-KEY'=>'Production','X-DS-TOKEN'=>$this->user_token]);
        if($this->core->request->error) {
            $this->sendTerminal('Error reading tests: '.$url);
            $this->sendTerminal($this->core->request->error);
            $this->core->request->error=false;
            $this->core->request->errorMsg=[];
            return null;
        }
        if(!$ret) {
            return($this->sendTerminal('There is not tests for your organization '.$org));
        }
        return $ret['data'];
    }
}
