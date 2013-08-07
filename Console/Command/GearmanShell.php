<?php
/**
 * Created by JetBrains PhpStorm.
 * User: u00f9w8
 * Date: 6-8-13
 * Time: 10:00
 * To change this template use File | Settings | File Templates.
 */

App::uses('CakeLog', 'Log');
App::uses('Hash', 'Utility');
App::uses('Configure', 'Core');

class GearmanShell extends AppShell {
    private $_gearmand;
    private $bin_gearman;
    private $_servers;
    private $_port;
    private $_address;
    private $_pid;

    private function _setVar($name, $value){
        $this->{$name} = $value;
    }

    public function main() {

        // is the function exec() allowed?
        if( ! function_exists('exec') ) {
            CakeLog::error(
                'Function exec() is not allowed, cannot continue',
                array('gearman')
            );
            return false;
        }

        // set the default binary for gearman when it's not set in the configuration
        $gearman = Configure::read('Gearman.clientbinary') ?: '/usr/bin/gearman';
        $this->_setVar('_gearman', $gearman);

        // set the default binary for gearman when it's not set in the configuration
        $gearmand = Configure::read('Gearman.serverbinary') ?: '/usr/sbin/gearmand';
        $this->_setVar('_gearmand', $gearmand);

        // set the port of the local server
        $port = Configure::read('Gearman.port') ?: 4730;
        $this->_setVar('_port', $port);

        // set the address and port of the local server
        $address = Configure::read('Gearman.address') ?: '127.0.0.1';
        $this->_setVar('_address', $address);

        // set the default servers
        $servers = Configure::read('Gearman.servers') ?: array('127.0.0.1:4730');
        $this->_setVar('_servers', $servers);

        // check whether gearman is installed
        if( ! $this->isGearmanOk() ){
            CakeLog::error('Gearman is not ok', array('gearman'));
            return false;
        }

        return true;
    }

    public function start() {
        $this->main();

        // check whether Gearman is not already running
        if( $this->_isGearmanRunning()){
            $this->out('Gearman is already running');
            return true;
        }

        $output = '';
        $command_return_value = '';

        $command = sprintf('%s --port %s --listen %s -d',
            $this->_gearmand,
            $this->_port,
            $this->_address
        );

        // fire the startup command
        exec( $command, $output, $command_return_value);

        CakeLog::debug(sprintf('executed command: %s', $command));
        CakeLog::debug(sprintf('got return value: %s', $command_return_value));
        CakeLog::debug(sprintf('got output: %s', implode(', ', $output)));

        if( $this->_isGearmanRunning()){
            $this->out(sprintf('Gearman succesfully started (with pid %s)', $this->_pid));
            return true;
        }

        $this->out(sprintf('Gearman could not be started'));
        return false;
    }

    public function stop() {

        $this->main();

        // check whether Gearman is not already running
        if( ! $this->_isGearmanRunning()){
            $this->out('Gearman is not running');
            return true;
        }

        // get the pid
        if( ! $this->_pid){
            $this->_getGearmanPid();
        }

        // kill the process
        if( $this->_killProcess($this->_pid)){
            $this->out('Gearman killed');
            return true;
        }

        $timeout = 3;
        CakeLog::debug(sprintf('Could not kill process %s', $this->_pid));
        CakeLog::debug(sprintf('Will wait for %s seconds and try to kill it again, this time with FIRE!', $timeout));

        sleep($timeout);

        if( ! $this->_killProcess($this->_pid)){
            $this->out(sprintf('Still could not kill process %s', $this->_pid));
            return false;
        }

        $this->out('Gearman killed');
        return true;

    }

    private function _killProcess($pid){
        $output = '';
        $command_return_value = '';
        $command = sprintf('kill %s', $pid);

        // fire the kill command
        exec( $command, $output, $command_return_value);

        CakeLog::debug(sprintf('executed command: %s', $command));
        CakeLog::debug(sprintf('got return value: %s', $command_return_value));
        CakeLog::debug(sprintf('got output: %s', implode(', ', $output)));

        sleep(2);

        // return the inverted (!) value of _isProcessRunning
        return ! $this->_isProcessRunning($pid);
    }

    public function restart() {
        $this->stop();
        $this->start();

    }

    /**
     * Check whether the installation of gearman is ok:
     * - can we find the gearman binary
     * - is the binary executable
     *
     * @return bool True when everything is in order, false otherwise
     */
    private function isGearmanOk(){

        // try to locate the gearman binary
        if( ! file_exists($this->_gearmand)){
            CakeLog::error(
                sprintf('Could not find gearman binary: %s', $this->_gearmand),
                array('gearman')
            );
            return false;
        }

        // make sure the binary is executable
        if( ! is_executable( $this->_gearmand) ){
            CakeLog::error(
                sprintf('Gearman binary is not executable: %s', $this->_gearmand),
                array('gearman')
            );
            return false;
        }

        // everything is ok!
        CakeLog::debug('Gearman is installed ok', array('gearman'));
        return true;
    }


    /**
     * Configures internal client reference to use the list of specified servers.
     * This list is specified using the configure class.
     *
     * @return void
     **/
    protected static function _setServers() {
        $servers = Configure::read('Gearman.servers') ?: array('127.0.0.1:4730');
        static::$_client->addServers(implode(',', $servers));
    }

    private function _getGearmanPid(){

        $output = '';
        $command_return_value = '';

        $command = sprintf("ps -ef | /bin/grep %s | /bin/grep -v grep | awk '{print $2}'",
            $this->_gearmand
        );

        // fire the startup command
        exec( $command, $output, $command_return_value);

        if( ! $command_return_value == 0 ){
            CakeLog::error(sprintf('Could not get gearman process from the ps command: %s', $command), array('gearman'));
            return false;
        }

        CakeLog::debug(sprintf('executed command: %s', $command));
        CakeLog::debug(sprintf('got return value: %s', $command_return_value));
        CakeLog::debug(sprintf('got output: %s', implode(', ', $output)));

        // when Gearman is not running, set the pid to 0
        $output = implode('', $output);
        if( empty($output)){
            $this->_pid = 0;
            return true;
        }

        // check that the pid is indeed a number
        if( preg_match('/[0-9]+/', $output) !== 1 ){
            CakeLog::error(sprintf('Retrieved process id appears not te be a number: %s', $output), array('gearman'));
            return false;
        }

        // set the pid
        $this->_setVar('_pid',$output);

    }

    private function _isProcessRunning($pid){

        // get the status
        $command = sprintf("ps -ef | /bin/grep %s | /bin/grep -v grep | awk '{print $2}'",
            $pid
        );

        // fire the ps command
        $output = '';
        $command_return_value = '';
        exec( $command, $output, $command_return_value);
        $output = implode('', $output);

        // when process is not found
        if( empty($output)){
            return false;
        }

        // when the found process is equal to our searched process
        if( $output == $pid ){
            return true;
        }

        return false;
    }

    /**
     * Check whether there is an active Gearman process
     *
     * @return bool
     */
    private function _isGearmanRunning(){
        if( ! $this->_pid){
            $this->_getGearmanPid();
        }

        if( $this->_pid === 0 ){
            return false;
        }

        return $this->_isProcessRunning($this->_pid);

    }

    /**
     * Check whether Gearman is active or not
     *
     * @return bool
     */
    public function status(){
        $this->main();

        if( ! $this->_isGearmanRunning() ){
            $this->out('Gearman is not running');
            return false;
        }

        $this->out( sprintf('Gearman is running (with pid %s)', $this->_pid));
        return true;
    }
}