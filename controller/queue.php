<?php
/**
 * ownCloud - ocDownloader
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE file.
 *
 * @author Xavier Beurois <www.sgc-univ.net>
 * @copyright Xavier Beurois 2015
 */

namespace OCA\ocDownloader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

use OCP\IL10N;
use OCP\IRequest;

use OCA\ocDownloader\Controller\Lib\Aria2;
use OCA\ocDownloader\Controller\Lib\CURL;
use OCA\ocDownloader\Controller\Lib\Tools;
use OCA\ocDownloader\Controller\Lib\Settings;

class Queue extends Controller
{
    private $UserStorage;
    private $DbType;
    private $CurrentUID;
    private $WhichDownloader = 0;
	private $DownloadsFolder;
	private $AbsoluteDownloadsFolder;

    public function __construct($AppName, IRequest $Request, $CurrentUID, IL10N $L10N)
    {
        parent::__construct($AppName, $Request);

        $this->DbType = 0;
        if (strcmp(\OC::$server->getConfig()->getSystemValue('dbtype'), 'pgsql') == 0) {
            $this->DbType = 1;
        }

        $this->CurrentUID = $CurrentUID;

        $Settings = new Settings();
        $Settings->setKey('WhichDownloader');
        $this->WhichDownloader = $Settings->getValue();
        $this->WhichDownloader = is_null($this->WhichDownloader) ? 0 :(strcmp($this->WhichDownloader, 'ARIA2') == 0 ? 0 : 1); // 0 means ARIA2, 1 means CURL

        $Settings->setTable('personal');
        $Settings->setUID($this->CurrentUID);
        $Settings->setKey('DownloadsFolder');
        $this->DownloadsFolder = $Settings->getValue();

        $this->DownloadsFolder = '/' .(is_null($this->DownloadsFolder)?'Downloads':$this->DownloadsFolder);
        $this->AbsoluteDownloadsFolder = \OC\Files\Filesystem::getLocalFolder($this->DownloadsFolder);
        $this->L10N = $L10N;
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function get()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['VIEW']) && strlen(trim($_POST['VIEW'])) > 0) {
                $Params = array($this->CurrentUID);
                switch ($_POST['VIEW']) {
                    case 'completes':
                        $StatusReq = '(?)';
                        $Params[] = 0;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    case 'removed':
                        $StatusReq = '(?)';
                        $Params[] = 4;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    case 'actives':
                        $StatusReq = '(?)';
                        $Params[] = 1;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    case 'stopped':
                        $StatusReq = '(?)';
                        $Params[] = 3;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    case 'waitings':
                        $StatusReq = '(?)';
                        $Params[] = 2;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    case 'all':
                        $StatusReq = '(?, ?, ?, ?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        $Params[] = 2;
                        $Params[] = 3;
                        $Params[] = 4;
                        $IsCleanedReq = '(?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        break;
                    default: // add view
                        $StatusReq = '(?, ?, ?, ?)';
                        $Params[] = 0;
                        $Params[] = 1;
                        $Params[] = 2;
                        $Params[] = 3; // STATUS
                        $IsCleanedReq = '(?)';
                        $Params[] = 0; // IS_CLEANED
                        break;
                }

                $SQL = 'SELECT * FROM `*PREFIX*ocdownloader_queue` WHERE `UID` = ? AND `STATUS` IN '
                . $StatusReq . ' AND `IS_CLEANED` IN ' . $IsCleanedReq . ' ORDER BY `TIMESTAMP` ASC';

                if ($this->DbType == 1) {
                    $SQL = 'SELECT * FROM *PREFIX*ocdownloader_queue WHERE "UID" = ? AND "STATUS" IN '
                    . $StatusReq . ' AND "IS_CLEANED" IN ' . $IsCleanedReq . ' ORDER BY "TIMESTAMP" ASC';
                }
                $Query = \OCP\DB::prepare($SQL);
                $Request = $Query->execute($Params);

                $Queue = [];

				$DownloadUpdated = false;
                while ($Row = $Request->fetchRow()) {
                    $Status =($this->WhichDownloader == 0
                        ?Aria2::tellStatus($Row['GID']):CURL::tellStatus($Row['GID']));
                    $DLStatus = 5; // Error

                    if (!is_null($Status)) {
                        if (!isset($Status['error'])) {
                            $Progress = 0;
                            if ($Status['result']['totalLength'] > 0) {
                                $Progress = $Status['result']['completedLength'] / $Status['result']['totalLength'];
                            }

                            $DLStatus = Tools::getDownloadStatusID($Status['result']['status']);
                            $ProgressString = Tools::getProgressString(
                                $Status['result']['completedLength'],
                                $Status['result']['totalLength'],
                                $Progress
                            );

                            $Queue[] = array(
                                'GID' => $Row['GID'],
                                'PROGRESSVAL' => round((($Progress) * 100), 2) . '%',
                                'PROGRESS' =>(is_null($ProgressString)
                                    ?(string)$this->L10N->t('N/A')
                                    :$ProgressString).(isset($Status['result']['bittorrent']) && $Progress < 1
                                        ?' - <strong>'.$this->L10N->t('Seeders').'</strong>: '.$Status['result']['numSeeders']
                                        :(isset($Status['result']['bittorrent']) && $Progress == 1
                                            ?' - <strong>'.$this->L10N->t('Uploaded').'</strong>: '.Tools::formatSizeUnits($Status['result']['uploadLength']).' - <strong>' . $this->L10N->t('Ratio') . '</strong>: ' . round(($Status['result']['uploadLength'] / $Status['result']['completedLength']), 2) : '')),
                                'STATUS' => isset($Status['result']['status'])
                                    ? $this->L10N->t(
                                        $Row['STATUS'] == 4?'Removed':ucfirst($Status['result']['status'])
                                    ).(isset($Status['result']['bittorrent']) && $Progress == 1 && $DLStatus != 3?' - '
                                    .$this->L10N->t('Seeding') : '') :(string)$this->L10N->t('N/A'),
                                'STATUSID' => $Row['STATUS'] == 4 ? 4 : $DLStatus,
                                'SPEED' => isset($Status['result']['downloadSpeed'])
                                    ?($Progress == 1
                                        ?(isset($Status['result']['bittorrent'])
                                            ?($Status['result']['uploadSpeed'] == 0
                                                ?'--'
                                                :Tools::formatSizeUnits($Status['result']['uploadSpeed']).'/s')
                                            :'--')
                                        :($DLStatus == 4
                                            ?'--'
                                            :Tools::formatSizeUnits($Status['result']['downloadSpeed']).'/s'))
                                    :(string)$this->L10N->t('N/A'),
                                'FILENAME' => $Row['FILENAME'],
                                'FILENAME_SHORT' => Tools::getShortFilename($Row['FILENAME']),
                                'PROTO' => $Row['PROTOCOL'],
                                'ISTORRENT' => isset($Status['result']['bittorrent'])
                            );

                            if ($Row['STATUS'] != $DLStatus) {
                                $SQL = 'UPDATE `*PREFIX*ocdownloader_queue`
                                    SET `STATUS` = ? WHERE `UID` = ? AND `GID` = ? AND `STATUS` != ?';
                                if ($this->DbType == 1) {
                                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue
                                        SET "STATUS" = ? WHERE "UID" = ? AND "GID" = ? AND "STATUS" != ?';
                                }

								$DownloadUpdated = true;

                                $Query = \OCP\DB::prepare($SQL);
                                $Result = $Query->execute(array(
                                $DLStatus,
                                $this->CurrentUID,
                                $Row['GID'],
                                4
                                ));
                            }
                        } else {
                            $Queue[] = array(
                                  'GID' => $Row['GID'],
                                  'PROGRESSVAL' => 0,
                                  'PROGRESS' =>(string)$this->L10N->t('Error, GID not found !'),
                                  'STATUS' =>(string)$this->L10N->t('N/A'),
                                  'STATUSID' => $DLStatus,
                                  'SPEED' =>(string)$this->L10N->t('N/A'),
                                  'FILENAME' => $Row['FILENAME'],
                                  'FILENAME_SHORT' => Tools::getShortFilename($Row['FILENAME']),
                                  'PROTO' => $Row['PROTOCOL'],
                                  'ISTORRENT' => isset($Status['result']['bittorrent'])
                            );
                        }
                    } else {
                        $Queue[] = array(
                              'GID' => $Row['GID'],
                              'PROGRESSVAL' => 0,
                              'PROGRESS' => $this->WhichDownloader==0
                                ?(string)$this->L10N->t('Returned status is null ! Is Aria2c running as a daemon ?')
                                :(string)$this->L10N->t('Unable to find download status file %s', '/tmp/'
                                .$Row['GID'].'.curl'),
                              'STATUS' =>(string)$this->L10N->t('N/A'),
                              'STATUSID' => $DLStatus,
                              'SPEED' =>(string)$this->L10N->t('N/A'),
                              'FILENAME' => $Row['FILENAME'],
                              'FILENAME_SHORT' => Tools::getShortFilename($Row['FILENAME']),
                              'PROTO' => $Row['PROTOCOL'],
                              'ISTORRENT' => isset($Status['result']['bittorrent'])
                        );
                    }
                }

				// Start rescan on update
				if ($DownloadUpdated) {
					Tools::rescanFolder(\OC\Files\Filesystem::getRoot() . $this->DownloadsFolder);
				}

                return new JSONResponse(
                    array(
                        'ERROR' => false,
                        'QUEUE' => $Queue,
                        'COUNTER' => Tools::getCounters($this->DbType, $this->CurrentUID)
                    )
                );
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function count()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            return new JSONResponse(
                array('ERROR' => false, 'COUNTER' => Tools::getCounters($this->DbType, $this->CurrentUID))
            );
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function pause()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if ($this->WhichDownloader == 0) {
                if (isset($_POST['GID']) && strlen(trim($_POST['GID'])) > 0) {
                    $Status = Aria2::tellStatus($_POST['GID']);

                    $Pause['result'] = $_POST['GID'];
                    if (!isset($Status['error']) && strcmp($Status['result']['status'], 'error') != 0
                        && strcmp($Status['result']['status'], 'complete') != 0
                        && strcmp($Status['result']['status'], 'active') == 0) {
                            $Pause = Aria2::pause($_POST['GID']);
                    }

                    if (strcmp($Pause['result'], $_POST['GID']) == 0) {
                        $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `STATUS` = ? WHERE `UID` = ? AND `GID` = ?';
                        if ($this->DbType == 1) {
                            $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "UID" = ? AND "GID" = ?';
                        }

                        $Query = \OCP\DB::prepare($SQL);
                        $Result = $Query->execute(array(
                              3,
                              $this->CurrentUID,
                              $_POST['GID']
                        ));

                        return new JSONResponse(
                            array('ERROR' => false, 'MESSAGE' =>(string)$this->L10N->t('The download has been paused'))
                        );
                    } else {
                        return new JSONResponse(
                            array(
                                'ERROR' => true,
                                'MESSAGE' =>(string)$this->L10N->t('An error occurred while pausing the download')
                            )
                        );
                    }
                } else {
                    return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
                }
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function unPause()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if ($this->WhichDownloader == 0) {
                if (isset($_POST['GID']) && strlen(trim($_POST['GID'])) > 0) {
                    $Status = Aria2::tellStatus($_POST['GID']);

                    $UnPause['result'] = $_POST['GID'];
                    if (!isset($Status['error']) && strcmp($Status['result']['status'], 'error') != 0
                        && strcmp($Status['result']['status'], 'complete') != 0
                        && strcmp($Status['result']['status'], 'paused') == 0) {
                            $UnPause = Aria2::unpause($_POST['GID']);
                    }

                    if (strcmp($UnPause['result'], $_POST['GID']) == 0) {
                        $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `STATUS` = ? WHERE `UID` = ? AND `GID` = ?';
                        if ($this->DbType == 1) {
                            $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "UID" = ? AND "GID" = ?';
                        }

                        $Query = \OCP\DB::prepare($SQL);
                        $Result = $Query->execute(array(
                              1,
                              $this->CurrentUID,
                              $_POST['GID']
                        ));

                        return new JSONResponse(
                            array(
                                'ERROR' => false,
                                'MESSAGE' =>(string)$this->L10N->t('The download has been unpaused')
                            )
                        );
                    } else {
                        return new JSONResponse(
                            array(
                                'ERROR' => true,
                                'MESSAGE' =>(string)$this->L10N->t('An error occurred while unpausing the download')
                            )
                        );
                    }
                } else {
                    return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
                }
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function hide()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GID']) && strlen(trim($_POST['GID'])) > 0) {
                $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `IS_CLEANED` = ? WHERE `UID` = ? AND `GID` = ?';
                if ($this->DbType == 1) {
                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "IS_CLEANED" = ? WHERE "UID" = ? AND "GID" = ?';
                }

                $Query = \OCP\DB::prepare($SQL);
                $Result = $Query->execute(array(
                      1,
                      $this->CurrentUID,
                      $_POST['GID']
                ));

                return new JSONResponse(
                    array('ERROR' => false, 'MESSAGE' =>(string)$this->L10N->t('The download has been cleaned'))
                );
            } else {
                return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function hideAll()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GIDS']) && count($_POST['GIDS']) > 0) {
                $Queue = array();

                foreach ($_POST['GIDS'] as $GID) {
                    $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `IS_CLEANED` = ? WHERE `UID` = ? AND `GID` = ?';
                    if ($this->DbType == 1) {
                        $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "IS_CLEANED" = ? WHERE "UID" = ? AND "GID" = ?';
                    }

                    $Query = \OCP\DB::prepare($SQL);
                    $Result = $Query->execute(array(
                          1,
                          $this->CurrentUID,
                          $GID
                    ));

                    $Queue[] = array(
                          'GID' => $GID
                    );
                }

                return new JSONResponse(
                    array(
                        'ERROR' => false,
                        'MESSAGE' =>(string)$this->L10N->t('All downloads have been cleaned'),
                        'QUEUE' => $Queue
                    )
                );
            } else {
                return new JSONResponse(
                    array(
                        'ERROR' => true,
                        'MESSAGE' =>(string)$this->L10N->t('No GIDS in the download queue')
                    )
                );
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function remove()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GID']) && strlen(trim($_POST['GID'])) > 0) {
                $Status =(
                    $this->WhichDownloader == 0
                    ?Aria2::tellStatus($_POST['GID'])
                    :CURL::tellStatus($_POST['GID'])
                );

                $Remove['result'] = $_POST['GID'];
                if (!isset($Status['error']) && strcmp($Status['result']['status'], 'error') != 0
                    && strcmp($Status['result']['status'], 'complete') != 0) {
                    $Remove =(
                        $this->WhichDownloader == 0
                        ? Aria2::remove($_POST['GID'])
                        :CURL::remove($Status['result'])
                    );
                } elseif ($this->WhichDownloader != 0 && strcmp($Status['result']['status'], 'complete') == 0) {
                    $Remove = CURL::remove($Status['result']);
                }

                if (!is_null($Remove) && strcmp($Remove['result'], $_POST['GID']) == 0) {
                    $SQL = 'UPDATE `*PREFIX*ocdownloader_queue`
                        SET `STATUS` = ?, `IS_CLEANED` = ? WHERE `UID` = ? AND `GID` = ?';
                    if ($this->DbType == 1) {
                        $SQL = 'UPDATE *PREFIX*ocdownloader_queue
                            SET "STATUS" = ?, "IS_CLEANED" = ? WHERE "UID" = ? AND "GID" = ?';
                    }

                    $Query = \OCP\DB::prepare($SQL);
                    $Result = $Query->execute(array(
                          4, 1,
                          $this->CurrentUID,
                          $_POST['GID']
                    ));

                    return new JSONResponse(
                        array(
                            'ERROR' => false,
                            'MESSAGE' =>(string)$this->L10N->t('The download has been removed')
                        )
                    );
                } else {
                    return new JSONResponse(
                        array(
                            'ERROR' => true,
                            'MESSAGE' =>(string)$this->L10N->t('An error occurred while removing the download')
                        )
                    );
                }
            } else {
                return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function removeAll()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GIDS']) && count($_POST['GIDS']) > 0) {
                $GIDS = array();

                foreach ($_POST['GIDS'] as $GID) {
                    $Status =($this->WhichDownloader == 0 ? Aria2::tellStatus($GID) : CURL::tellStatus($GID));
                    $Remove = array('result' => $GID);

                    if (!isset($Status['error']) && strcmp($Status['result']['status'], 'error') != 0
                        && strcmp($Status['result']['status'], 'complete') != 0) {
                        $Remove =($this->WhichDownloader == 0 ? Aria2::remove($GID) : CURL::remove($Status['result']));
                    }

                    if (!is_null($Remove) && strcmp($Remove['result'], $GID) == 0) {
                        $SQL = 'UPDATE `*PREFIX*ocdownloader_queue`
                            SET `STATUS` = ?, `IS_CLEANED` = ? WHERE `UID` = ? AND `GID` = ?';
                        if ($this->DbType == 1) {
                            $SQL = 'UPDATE *PREFIX*ocdownloader_queue
                                SET "STATUS" = ?, "IS_CLEANED" = ? WHERE "UID" = ? AND "GID" = ?';
                        }

                        $Query = \OCP\DB::prepare($SQL);
                        $Result = $Query->execute(array(
                              4, 1,
                              $this->CurrentUID,
                              $GID
                        ));

                        $GIDS[] = $GID;
                    }
                }

                return new JSONResponse(
                    array(
                        'ERROR' => false,
                        'MESSAGE' =>(string)$this->L10N->t('All downloads have been removed'),
                        'GIDS' => $GIDS
                    )
                );
            } else {
                return new JSONResponse(
                    array(
                        'ERROR' => true,
                        'MESSAGE' =>(string)$this->L10N->t('No GIDS in the download queue')
                    )
                );
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function completelyRemove()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GID']) && strlen(trim($_POST['GID'])) > 0) {
                $Status =(
                    $this->WhichDownloader == 0
                    ?Aria2::tellStatus($_POST['GID'])
                    :CURL::tellStatus($_POST['GID'])
                );

                if (!isset($Status['error']) && strcmp($Status['result']['status'], 'removed') == 0) {
                    $Remove =(
                        $this->WhichDownloader == 0
                        ? Aria2::removeDownloadResult($_POST['GID'])
                        :CURL::removeDownloadResult($_POST['GID'])
                    );
                }

                $SQL = 'DELETE FROM `*PREFIX*ocdownloader_queue` WHERE `UID` = ? AND `GID` = ?';
                if ($this->DbType == 1) {
                    $SQL = 'DELETE FROM *PREFIX*ocdownloader_queue WHERE "UID" = ? AND "GID" = ?';
                }

                $Query = \OCP\DB::prepare($SQL);
                $Result = $Query->execute(array(
                      $this->CurrentUID,
                      $_POST['GID']
                ));

                return new JSONResponse(
                    array(
                        'ERROR' => false,
                        'MESSAGE' =>(string)$this->L10N->t('The download has been totally removed')
                    )
                );
            } else {
                return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function completelyRemoveAll()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        try {
            if (isset($_POST['GIDS']) && count($_POST['GIDS']) > 0) {
                $GIDS = array();

                foreach ($_POST['GIDS'] as $GID) {
                    $Status =($this->WhichDownloader == 0 ? Aria2::tellStatus($GID) : CURL::tellStatus($GID));

                    if (!isset($Status['error']) && strcmp($Status['result']['status'], 'removed') == 0) {
                        $Remove =(
                            $this->WhichDownloader == 0
                            ?Aria2::removeDownloadResult($GID)
                            :CURL::removeDownloadResult($GID)
                        );
                    }

                    $SQL = 'DELETE FROM `*PREFIX*ocdownloader_queue` WHERE `UID` = ? AND `GID` = ?';
                    if ($this->DbType == 1) {
                        $SQL = 'DELETE FROM *PREFIX*ocdownloader_queue WHERE "UID" = ? AND "GID" = ?';
                    }

                    $Query = \OCP\DB::prepare($SQL);
                    $Result = $Query->execute(array(
                          $this->CurrentUID,
                          $GID
                    ));

                    $GIDS[] = $GID;
                }

                return new JSONResponse(
                    array(
                        'ERROR' => false,
                        'MESSAGE' =>(string)$this->L10N->t('The download has been totally removed'),
                        'GIDS' => $GIDS
                    )
                );
            } else {
                return new JSONResponse(array('ERROR' => true, 'MESSAGE' =>(string)$this->L10N->t('Bad GID')));
            }
        } catch (Exception $E) {
            return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
        }
    }
}
