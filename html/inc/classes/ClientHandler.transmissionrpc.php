<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

require_once("inc/classes/Transmission.class.php");

// Transmission RPC functions
require_once("inc/functions/functions.rpc.transmission.php");

/**
 * class ClientHandler for future compatible transmission-daemon RPC interface...
 */
class ClientHandlerTransmissionRPC extends ClientHandler
{

	// =========================================================================
	// constructor
	// =========================================================================

	public function __construct() {
		global $cfg;

		$this->type = "torrent";
		$this->client = "transmissionrpc";

		$this->binSocket = "transmission-daemon"; //for ps grep
		$this->binSystem = "transmission-daemon"; //script lang, not used in rpc
		$this->binClient = "transmission-daemon"; //for ps grep (ClientHandler.php)

		$this->useRPC = true;
	}

	// =========================================================================
	// public methods
	// =========================================================================

	/**
	 * starts a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $interactive (boolean) : is this a interactive startup with dialog ?
	 * @param $enqueue (boolean) : enqueue ?
	 */
	function start($transfer, $interactive = false, $enqueue = false) {
		global $cfg, $db;

		// set vars
		$this->_setVarsForTransfer($transfer);
		addGrowlMessage($this->client."-start",$transfer);

		if (!Transmission::isRunning()) {
			$msg = "Transmission RPC not reacheable, cannot start transfer ".$transfer;
			$this->logMessage($this->client."-start : ".$msg."\n", true);
			AuditAction($cfg["constants"]["error"], $msg);
			$this->logMessage($msg."\n", true);
			addGrowlMessage($this->client."-start",$msg);
			
			// write error to stat
			$sf = new StatFile($this->transfer, $this->owner);
			$sf->time_left = 'Error: RPC down';
			$sf->write();
			
			// return
			return false;
		}

		// init properties
		$this->_init($interactive, $enqueue, true, false);

		/*
		if (!is_dir($cfg["path"].'.config/transmissionrpc/torrents')) {
			if (!is_dir($cfg["path"].'.config'))
				mkdir($cfg["path"].'.config',0775);
			
			if (!is_dir($cfg["path"].'.config/transmissionrpc'))
				mkdir($cfg["path"].'.config/transmissionrpc',0775);
			
			mkdir($cfg["path"].'.config/transmissionrpc/torrents',0775);
		}
		*/
		if (!is_dir($cfg['path'].$cfg['user'])) {
			mkdir($cfg['path'].$cfg['user'],0777);
		}
		
		$this->command = "";
		if (getOwner($transfer) != $cfg['user']) {
			//directory must be changed for different users ?
			changeOwner($transfer,$cfg['user']);
			$this->owner = $cfg['user'];
			
			// change savepath
			$this->savepath = ($cfg["enable_home_dirs"] != 0)
				? $cfg['path'].$this->owner."/"
				: $cfg['path'].$cfg["path_incoming"]."/";
			
			$this->command = "re-downloading to ".$this->savepath;
			
		} else {
			$this->command = "downloading to ".$this->savepath;
		}

		// no client needed
		$this->state = CLIENTHANDLER_STATE_READY;

		// ClientHandler _start()
		$this->_start();

		$hash = getTransferHash($transfer);
		
		if (empty($hash) || !isTransmissionTransfer($hash)) {
			$hash = addTransmissionTransfer( $cfg['uid'], $cfg['transfer_file_path'].$transfer, $cfg['path'].$cfg['user'] );
			if (is_array($hash) && $hash["result"] == "duplicate torrent") {
				$this->command = 'torrent-add skipped, already exists '.$transfer; //log purpose
				$hash="";
				$sql = "SELECT hash FROM tf_transfers WHERE transfer = ".$db->qstr($transfer);
				$result = $db->Execute($sql);
				$row = $result->FetchRow();
				if (!empty($row)) {
					$hash=$row['hash'];
				}
			} else {
				$this->command .= "\n".'torrent-add '.$transfer.' '.$hash; //log purpose
			}
		} else {
			$this->command .= "\n". 'torrent-start '.$transfer.' '.$hash; //log purpose
		}
		if (!empty($hash)) {
/* to check...
			$sql = "DELETE FROM tf_transfer_totals WHERE tid = ".$db->qstr($hash)." AND uid=$uid";
			$result = $db->Execute($sql);
			$sql = "INSERT INTO tf_transfer_totals (tid,uid) VALUES (".$db->qstr($hash).", $uid)";
			$result = $db->Execute($sql);
*/
			$res = (int) startTransmissionTransfer($hash, $enqueue);
		}

		$this->updateStatFiles($transfer);

		// log
		$this->logMessage($this->client."-start : hash=".$hash." : $res \n", true);
	}

	/**
	 * stops a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $kill kill-param (optional)
	 * @param $transferPid transfer Pid (optional)
	 */
	function stop($transfer, $kill = false, $transferPid = 0) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-stop : ".$transfer."\n", true);

		// only if Transmission running
		if (!Transmission::isRunning()) {
			array_push($this->messages , "Transmission not running, cannot stop transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		if (empty($hash)) {
			//not in db, clean it
			@unlink($this->transferFilePath.".pid");
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : $transfer not in db, cleaning...");
			$this->delete($transfer);
			return true;
		}

		if (!stopTransmissionTransfer($hash)) {
			$rpc = Transmission::getInstance();
			$msg = $transfer." :". $rpc->lastError;
			$this->logMessage($msg."\n", true);
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : error $msg.");
		}

		$this->updateStatFiles($transfer);

		// delete .pid
		$this->_stop($kill, $transferPid);

		// set .stat stopped
		$this->cleanStoppedStatFile($transfer);
	}

	/**
	 * deletes a transfer
	 *
	 * @param $transfer name of the transfer
	 * @return boolean of success
	 */
	function delete($transfer) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-delete : ".$transfer."\n", true);

		// only if vuze running and transfer exists in fluazu
		if (!Transmission::isRunning()) {
			array_push($this->messages , "Transmission not running, cannot stop transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		deleteTransmissionTransfer($cfg['uid'], $hash, false);

		// delete
		return $this->_delete();
	}

	/**
	 * gets current transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrent($transfer) {
		global $db, $transfers;
		$retVal = array();
		return $retVal;
	}

	/**
	 * gets current transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrentOP($transfer, $tid, $sfu, $sfd) {
		global $transfers;
		$retVal = array();
		$retVal["uptotal"] = (isset($transfers['totals'][$tid]['uptotal']))
			? $sfu - $transfers['totals'][$tid]['uptotal']
			: $sfu;
		$retVal["downtotal"] = (isset($transfers['totals'][$tid]['downtotal']))
			? $sfd - $transfers['totals'][$tid]['downtotal']
			: $sfd;
		return $retVal;
	}

	/**
	 * gets total transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotal($transfer) {
		global $transfers;
		// transfer from stat-file
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	/**
	 * gets total transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotalOP($transfer, $tid, $sfu, $sfd) {
		return array("uptotal" => $sfu, "downtotal" => $sfd);
	}

	/**
	 * set upload rate of a transfer
	 *
	 * @param $transfer
	 * @param $uprate
	 * @param $autosend
	 */
	function setRateUpload($transfer, $uprate, $autosend = false) {
		// set rate-field
		$this->rate = $uprate;
	}

	/**
	 * set download rate of a transfer
	 *
	 * @param $transfer
	 * @param $downrate
	 * @param $autosend
	 */
	function setRateDownload($transfer, $downrate, $autosend = false) {
		// set rate-field
		$this->drate = $downrate;
	}

	/**
	 * set runtime of a transfer
	 *
	 * @param $transfer
	 * @param $runtime
	 * @param $autosend
	 * @return boolean
	 */
	function setRuntime($transfer, $runtime, $autosend = false) {
		// set runtime-field
		$this->runtime = $runtime;
		// return
		return true;
	}

	/**
	 * set sharekill of a transfer
	 *
	 * @param $transfer
	 * @param $sharekill
	 * @param $autosend
	 * @return boolean
	 */
	function setSharekill($transfer, $sharekill, $autosend = false) {
		// set sharekill
		$this->sharekill = $sharekill;
		// return
		return true;
	}

	/**
	 * (test) gets array of running transfers (via call to transmission-remote)
	 *
	 * @return array
	 */
	function runningTransfers() {
		global $cfg;

		$host = $cfg['transmission_rpc_host'].":".$cfg['transmission_rpc_port'];
		$userpw = $cfg['transmission_rpc_user'];
		if (!empty($cfg['transmission_rpc_password']))
			$userpw .= ':'.$cfg['transmission_rpc_password'];

		$screenStatus = shell_exec("/usr/bin/transmission-remote $userpw@$host --list");
		$retAry = explode("\n",$screenStatus);
		print_r($retAry);
		return $retAry;
	}

	/**
	 * clean stat file
	 *
	 * @param $transfer
	 * @return boolean
	 */
	function cleanStoppedStatFile($transfer) {
		$stat = new StatFile($this->transfer, $this->owner);
		//if ($stat->percent_done > 100)
		//	$stat->percent_done=100;
		return $stat->stop();
	}

	/**
	 * updateStatFiles
	 *
	 * @param $transfer string torrent name
	 * @return boolean
	 */
	function updateStatFiles($transfer="") {
		global $cfg, $db;
		
	}

	/**
	 * gets current status of one Transfer (realtime)
	 * for transferStat popup
	 *
	 * @return array (stat) or Error String
	 */
	function monitorTransfer($transfer) {
		//by default, monitoring not available.

		// set vars
		$this->_setVarsForTransfer($transfer);

		if (!isHash($transfer))
			$hash = getTransferHash($transfer);

		if (empty($hash)) {
			return "Hash for $transfer was not found";
		}

		$fields = array("id", "name", "eta", "downloadedEver", "hashString", "fileStats", "totalSize", "percentDone", 
						"metadataPercentComplete", "peersConnected", "rateDownload", "rateUpload", "status", "files", "trackerStats", "uploadLimit", "uploadRatio"  );

		$stat = getTransmissionTransfer($hash, $fields);
		if (is_array($stat)) {
			return $stat;
		} else {
			$rpc = Transmission::getInstance();
			return $rpc->lastError;
		}
	}

	/**
	 * gets current status of all Transfers (realtime)
	 *
	 * @return array (stat) or Error String
	 */
	function monitorAllTransfers() {
		//by default, monitoring not available.
		//$rpc = Transmission::getInstance();

		return getUserTransmissionTransfers();
	}

	/**
	 * gets current status of all Running Transfers (realtime)
	 *
	 * @return array (stat) or Error String
	 */
	function monitorRunningTransfers() {
		//by default, monitoring not available.
		$aTorrent = getUserTransmissionTransfers();

		$stat=array();
		foreach ($result as $aTorrent) {
			if ( $aTorrent['status']==4 || $aTorrent['status']==8 ) $stat[]=$aTorrent;
		}
		return $stat;
	}
}

?>
