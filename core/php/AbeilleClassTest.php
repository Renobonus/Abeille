<?php

    /* This file is part of Jeedom.
     *
     * Jeedom is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * Jeedom is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
     */

    /* Developers debug features */
    $dbgFile = __DIR__."/../../tmp/debug.php";
    if (file_exists($dbgFile)) {
        include_once $dbgFile;
        /* Dev mode: enabling PHP errors logging */
        error_reporting(E_ALL);
        ini_set('error_log', __DIR__.'/../../../../log/AbeillePHP.log');
        ini_set('log_errors', 'On');
    }

    include_once __DIR__.'/../../../../core/php/core.inc.php';
    include_once __DIR__.'/../../resources/AbeilleDeamon/includes/config.php';
    include_once __DIR__.'/../../resources/AbeilleDeamon/includes/function.php';
    include_once __DIR__.'/../../resources/AbeilleDeamon/includes/fifo.php';
    include_once __DIR__.'/../../resources/AbeilleDeamon/lib/AbeilleTools.php';
    include_once __DIR__.'/AbeilleMsg.php';
    include_once __DIR__.'/AbeilleCmd.class.php';
    include_once __DIR__.'/../../plugin_info/install.php'; // updateConfigDB()

class Abeille extends eqLogic
{

    // https://github.com/KiwiHC16/Abeille/issues/1055
    public static function replaceGhost($ghostId, $realId)
    {
        //lors de l association sur la zigate Y, une nouvel Abeille va etre créée en AbeilleY/YYYY a reception de son nom alors que AbeilleX/XXXX existe toujours.
        // On va avoir ce doublon jusqu'à reception de IEEE ou action utilisateur. Ce qui veut dire que sur reception de IEEE pour AbeilleY/YYYY, il faut gérer le doublon. Il faut:
        // -- supprimer de la Zigate X, l'appairage avec l IEEEE
        // -- migrer l historique des commandes AbeilleX/XXXX vers AbeilleY/YYYY,
        // -- migrer les instances des commandes dans scenario et autres
        // -- supprimer l Abeille: AbeilleX/XXXX

        // Collect all needed infos
        $ghost = Abeille::byId($ghostId);
        if (!is_object($ghost)) return;
        $real = Abeille::byId($realId);
        if (!is_object($real)) return;

        list($destGhost, $shortGhost) = explode('/', $ghost->getLogicalId());
        list($destReal, $shortReal) = explode('/', $real->getLogicalId());
        $IEEE = $ghost->getConfiguration('IEEE', 'none');
        if ($IEEE == 'none') {
            $IEEE = $real->getConfiguration('IEEE', 'none');
        }

        // -- si sur deux zigate differentes: supprimer l'appairage avec l IEEE de la Zigate X
        if ($destGhost != $destReal) {
            if ($IEEE == 'none') {
                log::add('Abeille', 'debug', 'Erreur je n ai pas l IEEE je ne sais comment gerer.');
                return;
            }
            self::publishMosquitto(queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd" . $destGhost . "/Ruche/Remove", "IEEE=" . $IEEE);
        }

            // -- si sur deux zigate differentes: supprimer l'appairage avec l IEEE de la Zigate X
            if ( $destGhost != $destReal ) {
                if ( $IEEE == 'none' ) {
                    log::add('Abeille', 'debug', 'Erreur je n ai pas l IEEE je ne sais comment gerer.');
                    return;
                }
                self::publishMosquitto( queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd".$destGhost."/Ruche/Remove", "IEEE=".$IEEE );
            }

        // Parcours toutes les commandes
        foreach ($ghost->getCmd() as $numGhost => $ghostCmd) {
            foreach ($real->getCmd() as $numReal => $realCmd) {
                if ($ghostCmd->getLogicalId() == $realCmd->getLogicalId()) {
                    if ($ghostCmd->getSubType() == $realCmd->getSubType()) {
                        if ($ghostCmd->getType() == 'info' && $realCmd->getType() == 'info') {
                            if ($ghostCmd->getIsHistorized() == 1) {
                                // -- migrer l historique des commandes AbeilleY/YYYY vers AbeilleX/XXXX, les instances des commandes dans scenario et autres
                                // history::copyHistoryToCmd('#17009#', '#17251#');
                                echo 'Copy history from ' . $ghostCmd->getName() . ' to ' . $realCmd->getName() . "\n";
                                history::copyHistoryToCmd($ghostCmd->getId(), $realCmd->getId());
                            }
                        }
                        // -- migrer les instances des commandes dans scenario et autres
                        jeedom::replaceTag(array('#' . $ghostCmd->getId() . '#' => '#' . $realCmd->getId() . '#'));
                    }
                }
            }
        }

        // -- supprimer l Abeille: AbeilleY/YYYY
        $ghost->remove();

        return;
    }

    // Fonction dupliquée dans AbeilleParser.
    public static function volt2pourcent($voltage)
    {
        $max = 3.135;
        $min = 2.8;
        if ($voltage / 1000 > $max) {
            log::add('Abeille', 'debug', 'Voltage remonte par le device a plus de ' . $max . 'V. Je retourne 100%.');
            return 100;
        }
        if ($voltage / 1000 < $min) {
            log::add('Abeille', 'debug', 'Voltage remonte par le device a moins de ' . $min . 'V. Je retourne 0%.');
            return 0;
        }
        return round(100 - ((($max - ($voltage / 1000)) / ($max - $min)) * 100));
    }

    // Is it the health of the plugin level menu Analyse->santé ? A verifier.
    public static function health()
    {
        $return = array();

        $return[] = array(
            'test' => 'OK',
            'result' => 'OK',
            'advice' => 'OK',
            'state' => 'OK',
        );

        return $return;
    }

    /* Looking for missing IEEE addresses */
    public static function tryToGetIEEE()
    {
        log::add('Abeille', 'debug', 'Recherche des adresses IEEE manquantes.');
        $tryToGetIEEEArray = array();
        $eqLogics = Abeille::byType('Abeille');
        // var_dump($eqLogics);
        foreach ($eqLogics as $key => $eqLogic) {
            if ($eqLogic->getIsEnable() != 1) {
                log::add('Abeille', 'debug', '  Eq \'' . $eqLogic->getLogicalId() . '\' désactivé => ignoré');
                continue; // Eq disabled => ignored
            }
            if ($eqLogic->getTimeout() == 1) {
                log::add('Abeille', 'debug', '  Eq \'' . $eqLogic->getLogicalId() . '\' en timeout => ignoré');
                continue; // Eq in timeout => ignored
            }
            if ($eqLogic->getStatus('lastCommunication') == '') {
                log::add('Abeille', 'debug', '  Eq \'' . $eqLogic->getLogicalId() . '\' n\'a jamais communiqué => ignoré');
                continue; // Eq in timeout => ignored
            }

            if (strlen($eqLogic->getConfiguration('IEEE', 'none')) == 16) {
                continue; // J'ai une adresse IEEE dans la conf donc je passe mon chemin
            }

            $commandIEEE = $eqLogic->getCmd('info', 'IEEE-Addr');
            if ($commandIEEE == null) {
                log::add('Abeille', 'debug', '  Eq \'' . $eqLogic->getLogicalId() . '\' sans cmd \'IEEE-Addr\' => ignoré');
                continue; // No cmd to retrieve IEEE address. Normal ?
            }

            if (strlen($commandIEEE->execCmd()) == 16) {
                $eqLogic->setConfiguration('IEEE', $commandIEEE->execCmd()); // Si je suis a cette ligne c est que je n ai pas IEEE dans conf mais dans cmd alors je mets dans conf.
                $eqLogic->save();
                $eqLogic->refresh();
                continue; // J'ai une adresse IEEE dans la commande donc je passe mon chemin
            }

            $tryToGetIEEEArray[] = $key;
        }

        // Prend x abeilles au hasard dans cette liste d'abeille a interroger.
        $eqLogicIds = array_rand( $tryToGetIEEEArray, 2 );

        // Pour ces x Abeilles lance l interrogation
        foreach ($eqLogicIds as $eqLogicId) {
            echo "Start Loop: ".$eqLogicId."\n";
            // echo "Start Loop Detail: ";
            $eqLogicX = $eqLogics[$tryToGetIEEEArray[$eqLogicId]];
            // var_dump($eqLogic);
            $commandIEEE_X = $eqLogicX->getCmd('info', 'IEEE-Addr');
            if ( $commandIEEE_X ) {
                $addrIEEE_X = $commandIEEE_X->execCmd();
                if (strlen($addrIEEE_X) < 2 ) {
                    list( $dest, $NE) = explode('/', $eqLogicX->getLogicalId());
                    if (strlen($NE) == 4) {
                        if ( $eqLogicX->getIsEnable() ) {
                            log::add('Abeille', 'debug', 'Demarrage tryToGetIEEE for '.$NE);
                            echo 'Demarrage tryToGetIEEE for '.$NE."\n";
                            $cmd = "/usr/bin/nohup php ".__DIR__."/../php/AbeilleInterrogate.php ".$dest." ".$NE." >/dev/null 2>&1 &";
                            // echo "Cmd: ".$cmd."\n";
                            exec($cmd, $out, $status);
                        }
                        else echo "Je n essaye pas car Abeille inactive.\n";
                    }
                    else echo "Je n ai pas recuperé l adresse courte !!!\n";
                }
                else echo "IEEE superieure à deux carateres !!! :".$addrIEEE_X."\n";
            }
            else echo "commandIEEE n existe pas !!!!\n";
        }
    }

    public static function updateConfigAbeille($abeilleIdFilter = false)
    {

    }

    public static function pollingCmd($period)
    {
        $cmds = AbeilleCmd::searchConfiguration('Polling', 'Abeille');
        foreach ($cmds as $key => $cmd) {
            echo $cmd->getName() . " - " . $cmd->getConfiguration('Polling') . "\n";
            if ($cmd->getConfiguration('Polling') == $period) {
                $cmd->execute();
            }
        }
    }

    public static function cronDaily()
    {
        log::add('Abeille', 'debug', 'Starting cronDaily ------------------------------------------------------------------------------------------------------------------------');
        /*
             * Refresh LQI once a day to get IEEE in prevision of futur changes, to get network topo as fresh as possible in json
             */
        log::add('Abeille', 'debug', 'cronD: Lancement de l\'analyse réseau (AbeilleLQI.php)');
        $ROOT = __DIR__ . "/../../Network";
        $nbOfZigates = config::byKey('zigateNb', 'Abeille', '1', 1);
        for ($zgNb = 1; $zgNb <= $nbOfZigates; $zgNb++) {
            $zgEnabled = config::byKey('AbeilleActiver' . $zgNb, 'Abeille', 'N', 1);
            if ($zgEnabled != 'Y')
                continue; // Zigate disabled
            $cmd = "cd " . $ROOT . "; nohup /usr/bin/php AbeilleLQI.php " . $zgNb . " 1>/dev/null 2>/dev/null &";
            log::add('Abeille', 'debug', 'cronD: cmd=\'' . $cmd . '\'');
            exec($cmd);
        }

        // Poll Cmd
        self::pollingCmd("cronDaily");
    }

    public static function cronHourly()
    {
        log::add('Abeille', 'debug', 'Starting cronHourly ------------------------------------------------------------------------------------------------------------------------');

        //--------------------------------------------------------
        // Check Zigate Presence

        log::add('Abeille', 'debug', 'Check Zigate Presence');

        $param = self::getParameters();

        //--------------------------------------------------------
        // Pull IEEE
        // self::tryToGetIEEE();

        //--------------------------------------------------------
        // Refresh Ampoule Ikea Bind et set Report

        log::add('Abeille', 'debug', 'Refresh Ampoule Ikea Bind et set Report');

        $eqLogics = Abeille::byType('Abeille');
        $i = 0;
        foreach ($eqLogics as $eqLogic) {
            // log::add('Abeille', 'debug', 'Icone: '.$eqLogic->getConfiguration("icone"));
            if (strpos("_" . $eqLogic->getConfiguration("icone"), "IkeaTradfriBulb") > 0) {
                list($dest, $addr) = explode("/", $eqLogic->getLogicalId());
                $i = $i + 1;

                $ruche = new Abeille();
                $commandIEEE = new AbeilleCmd();

                // Recupere IEEE de la Ruche/ZiGate
                $rucheId = $ruche->byLogicalId($dest . '/Ruche', 'Abeille')->getId();
                // log::add('Abeille', 'debug', 'Id pour abeille Ruche: ' . $rucheId);

                if (strlen($ruche->byLogicalId($dest . '/Ruche', 'Abeille')->getConfiguration('IEEE', 'none')) == 16) {
                    $ZiGateIEEE = $ruche->byLogicalId($dest . '/Ruche', 'Abeille')->getConfiguration('IEEE', 'none');
                } else {
                    $ZiGateIEEE = $commandIEEE->byEqLogicIdAndLogicalId($rucheId, 'IEEE-Addr')->execCmd();
                    // log::add('Abeille', 'debug', 'IEEE pour  Ruche: ' . $ZiGateIEEE);
                }

                $abeille = new Abeille();
                $commandIEEE = new AbeilleCmd();

                // Recupere IEEE de la Ruche/ZiGate
                $abeilleId = $abeille->byLogicalId($eqLogic->getLogicalId(), 'Abeille')->getId();
                // log::add('Abeille', 'debug', 'Id pour abeille Ruche: ' . $rucheId);

                if (strlen($abeille->byLogicalId($eqLogic->getLogicalId(), 'Abeille')->getConfiguration('IEEE', 'none')) == 16) {
                    $addrIEEE = $abeille->byLogicalId($eqLogic->getLogicalId(), 'Abeille')->getConfiguration('IEEE', 'none');
                } else {
                    $addrIEEE = $commandIEEE->byEqLogicIdAndLogicalId($abeilleId, 'IEEE-Addr')->execCmd();
                    // log::add('Abeille', 'debug', 'IEEE pour abeille: ' . $addrIEEE);
                }

                log::add('Abeille', 'debug', 'Refresh bind and report for Ikea Bulb: ' . $addr);
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $dest . "/Ruche/bindShort&time=" . (time() + (($i * 33) + 1)), "address=" . $addr . "&targetExtendedAddress=" . $addrIEEE . "&targetEndpoint=01&ClusterId=0006&reportToAddress=" . $ZiGateIEEE);
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $dest . "/Ruche/bindShort&time=" . (time() + (($i * 33) + 2)), "address=" . $addr . "&targetExtendedAddress=" . $addrIEEE . "&targetEndpoint=01&ClusterId=0008&reportToAddress=" . $ZiGateIEEE);
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $dest . "/Ruche/setReport&time=" . (time() + (($i * 33) + 3)), "address=" . $addr . "&ClusterId=0006&AttributeId=0000&AttributeType=10");
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $dest . "/Ruche/setReport&time=" . (time() + (($i * 33) + 4)), "address=" . $addr . "&ClusterId=0008&AttributeId=0000&AttributeType=20");
            }
        }
        if (($i * 33) > (3600)) {
            message::add("Abeille", "Danger il y a trop de message a envoyer dans le cron 1 heure.", "Contactez KiwiHC16 sur le Forum.");
        }

        // Poll Cmd
        self::pollingCmd("cronHourly");

        log::add('Abeille', 'debug', 'Ending cronHourly ------------------------------------------------------------------------------------------------------------------------');
    }

    public static function cron15()
    {

        log::add('Abeille', 'debug', 'Starting cron15 ------------------------------------------------------------------------------------------------------------------------');

        /**
         * Look every 15 minutes if the kernel driver is not in error
         */
        // $param = self::getParameters();

        log::add('Abeille', 'debug', 'Check USB driver potential crash');
        $cmd = "egrep 'pl2303' /var/log/syslog | tail -1 | egrep -c 'failed|stopped'";
        $output = array();
        exec(system::getCmdSudo() . $cmd, $output);
        $usbZigateStatus = !is_null($output) ? (is_numeric($output[0]) ? $output[0] : '-1') : '-1';
        if ($usbZigateStatus != '0') {
            message::add("Abeille", "Erreur, le pilote pl2303 est en erreur, impossible de communiquer avec la zigate.", "Il faut débrancher/rebrancher la zigate et relancer le demon.");
            log::add('Abeille', 'debug', 'Ending cron15 ------------------------------------------------------------------------------------------------------------------------');
        }

            log::add('Abeille', 'debug', 'cron15(): Interrogation des équipements sur secteur.');
            /* TODO: We should interrogate only if eq itself did not send anything since a while */
            $eqLogics = Abeille::byType('Abeille');
            $i = 0;
            foreach ($eqLogics as $eqLogic) {
                $enabled = $eqLogic->getIsEnable();
                if ($enabled == 0)
                    continue; // Equipment disabled
                if (strlen($eqLogic->getConfiguration("battery_type")) == 0) {
                    $topicArray = explode("/", $eqLogic->getLogicalId());
                    $dest = $topicArray[0];
                    $addr = $topicArray[1];
                    if (strlen($addr) != 4)
                        continue;
                    // echo "Short: " . $topicArray[1];
                    log::add('Abeille', 'debug', 'cron15(): Interrogation adresse '.$addr);
                    // Ca devrait être le fonctionnement normal
                    $mainEP = $eqLogic->getConfiguration("mainEP");
                    if (strlen($mainEP) > 1) {
                        Abeille::publishMosquitto( queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd".$dest."/".$addr."/Annonce&time=".(time()+($i*23)), $mainEP);
                        $i++;
                    }
                }
        }
        if (($i * 23) > (60 * 15)) {
            message::add("Abeille", "Danger il y a trop de messages à envoyer dans le cron 15 minutes. Cas A.", "Contacter KiwiHC15 sur le forum");
        }

        // Rafraichie l etat poll = 15
        log::add('Abeille', 'debug', 'cron15(): Interrogation des équipements nécessitant un polling.');
        $i = 0;
        foreach ($eqLogics as $eqLogic) {
            $enabled = $eqLogic->getIsEnable();
            if ($enabled == 0)
                continue; // Equipment disabled
            $dest = explode("/", $eqLogic->getLogicalId())[0];
            $address = explode("/", $eqLogic->getLogicalId())[1];
            if (strlen($address) != 4)
                continue; // Incorrect address
            if ($eqLogic->getConfiguration("poll") == "15") {
                log::add('Abeille', 'debug', 'cron15(): GetEtat/GetLevel adresse ' . $address);
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $dest . "/" . $address . "/ReadAttributeRequest&time=" . (time() + ($i * 13)), "EP=" . $eqLogic->getConfiguration('mainEP') . "&clusterId=0006&attributeId=0000");
                $i++;
            }
        }
        if (($i * 13) > (60 * 15)) {
            message::add("Abeille", "Danger il y a trop de messages à envoyer dans le cron 15 minutes. Cas B.", "Contacter KiwiHC16 sur le forum");
        }

        // Poll Cmd
        self::pollingCmd("cron15");

        log::add('Abeille', 'debug', 'Ending cron15 ------------------------------------------------------------------------------------------------------------------------');

        return;
    }

        /* Called every 1 min. Jeedom requirement. */
        public static function cron() {
            // log::add( 'Abeille', 'debug', 'cron1: Start ------------------------------------------------------------------------------------------------------------------------' );
        $param = self::getParameters();

        // https://github.com/jeelabs/esp-link
        // The ESP-Link connections on port 23 and 2323 have a 5 minute inactivity timeout.
        // so I need to create a minimum of traffic, so pull zigate every minutes
        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if ($param['AbeilleActiver' . $i] != 'Y')
                continue; // Zigate disabled
            if ($param['AbeilleSerialPort' . $i] == "none")
                continue; // Serial port undefined

            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmdAbeille" . $i . "/Ruche/getVersion&time=" . (time() + 20), "Version");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmdAbeille" . $i . "/Ruche/getNetworkStatus&time=" . (time() + 24), "getNetworkStatus");
        }

        $eqLogics = self::byType('Abeille');

            /* Refresh status for equipements which require 1min polling */
        $i = 0;
        foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getConfiguration("poll") != "1")
                    continue; // No 1min polling requirement
                if ($eqLogic->getIsEnable() == 0)
                    continue; // Equipment disabled
            list($dest, $address) = explode("/", $eqLogic->getLogicalId());
                if (strlen($address) != 4)
                    continue; // Bad address

                log::add('Abeille', 'debug', 'cron1: GetEtat/GetLevel, addr='.$address);
                $mainEP = $eqLogic->getConfiguration('mainEP');
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd".$dest."/".$address."/ReadAttributeRequest&time=".(time()+($i*3)), "EP=".$mainEP."&clusterId=0006&attributeId=0000");
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd".$dest."/".$address."/ReadAttributeRequest&time=".(time()+($i*3)), "EP=".$mainEP."&clusterId=0008&attributeId=0000");
                $i++;
        }
        if (($i * 3) > 60) {
                message::add("Abeille", "Danger ! Il y a trop de messages à envoyer dans le cron 1 minute.", "Contacter KiwiHC15 sur le forum." );
        }

        // Poll Cmd
        self::pollingCmd("cron");

        /**
         * Refresh health information
         */
        // log::add('Abeille', 'debug', '----------- Refresh health information');
        //$eqLogics = self::byType('Abeille');

        foreach ($eqLogics as $eqLogic) {

            if ($eqLogic->getTimeout() > 0) {
                if (strtotime($eqLogic->getStatus('lastCommunication')) > 0) {
                    $last = strtotime($eqLogic->getStatus('lastCommunication'));
                } else {
                    $last = 0;
                }

                // log::add('Abeille', 'debug', '--');
                // log::add( 'Abeille', 'debug', 'Name: '.$eqLogic->getName().' Last: '.$last.' Timeout: '.$eqLogic->getTimeout( ).'s - Last+TimeOut: '.($last + $eqLogic->getTimeout()).' now: '.time().' Delta: '.(time( ) - ($last + $eqLogic->getTimeout())) );

                // Alerte sur TimeOut Defini
                if (($last + (60 * $eqLogic->getTimeout())) > time()) {
                    // Ok
                    $eqLogic->setStatus('state', 'ok');
                    $eqLogic->setStatus('timeout', 0);
                } else {
                    // NOK
                    $eqLogic->setStatus('state', 'Time Out Last Communication');
                    $eqLogic->setStatus('timeout', 1);
                }

                // ===============================================================================================================================

                // log::add( 'Abeille', 'debug', 'Name: '.$eqLogic->getName().' lastCommunication: '.$eqLogic->getStatus( "lastCommunication" ).' timeout value: '.$eqLogic->getTimeout().' timeout status: '.$eqLogic->getStatus( 'timeout' ).' state: '.$eqLogic->getStatus('state'));

            } else {
                $eqLogic->setStatus('state', '-');
                $eqLogic->setStatus('timeout', 0);
            }
        }

        // Si Inclusion status est à 1 on demande un Refresh de l information
        // Je regarde si j ai deux zigate en inclusion et si oui je genere une alarme.
        $count = array();
        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if (self::checkInclusionStatus("Abeille" . $i) == "01") {
                Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "CmdAbeille" . $i . "/Ruche/permitJoin", "Status");
                $count[] = $i;
            }
        }
        if (count($count) > 1) message::add("Abeille", "Danger vous avez plusieurs Zigate en mode inclusion: " . json_encode($count) . ". L equipement peut se joindre a l un ou l autre resau zigbee.", "Vérifier sur quel reseau se joint l equipement.");

            // log::add( 'Abeille', 'debug', 'cron1: Fin ------------------------------------------------------------------------------------------------------------------------' );
    }

    public static function deamon_info()
    {
        $debug_deamon_info = 0;
        // log::add('Abeille', 'debug', 'deamon_info(): Démarrage'); // Useless

        // On suppose que tout est bon et on cherche les problemes.
        $return = array('state' => 'ok',  // On couvre le fait que le process tourne en tache de fond
            'launchable' => 'ok',  // On couvre la configuration de plugin
            'launchable_message' => "",);

        // On vérifie que le demon est demarrable
        // On verifie qu'on n'a pas d erreur dans la recuperation des parametres
        $parameters = self::getParameters();
        if ($parameters['parametersCheck'] != "ok") {
            log::add('Abeille', 'warning', 'deamon_info(): parametersCheck NOT ok');
            $return['launchable'] = $parameters['parametersCheck'];
            $return['launchable_message'] = $parameters['parametersCheck_message'];
        }

        // si la cron tourne alors le plugin a été démarré.
        if (AbeilleTools::isAbeilleCronRunning() == false) {
            $return['state'] = "nok";
            log::add('Abeille', 'warning', 'deamon_info(): Le plugin n\'est pas démarré. la cron ne tourne pas');
            return $return;
        }
        log::add('Abeille', 'info', 'deamon_info(): Le plugin est démarré. la cron tourne');

        // Nb de demon devant tourner: Parser + cmd + n x ( Zigate serial ) + socat si wifi

        $nbProcessExpected = 0; // Comptons les process prevus.
        $running = AbeilleTools::getRunningDaemons();
        //get last start to limit restart requests
        if (AbeilleTools::isMissingDaemons($parameters, $running) == true) {
            $return['state'] = "nok";
            for ($retry = 1; $retry <= 3; $retry++) {
                AbeilleTools::restartMissingDaemons($parameters, $running);
                if (AbeilleTools::isMissingDaemons($parameters, $running) == true) {
                    $return['state'] = "ok";
                    break;
                }
            }
            //After restart daemons, if still are missing, then no hope.
            if (AbeilleTools::isMissingDaemons($parameters, $running)) {
                return $return;
            }
        }

        // Check ipcs situation pour detecter des soucis eventuels
        if (msg_stat_queue(msg_get_queue(queueKeyAbeilleToAbeille))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyAbeilleToAbeille');
        if (msg_stat_queue(msg_get_queue(queueKeyAbeilleToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyAbeilleToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeyParserToAbeille))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyParserToAbeille');
        if (msg_stat_queue(msg_get_queue(queueKeyParserToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyParserToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeyParserToLQI))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyParserToLQI');
        if (msg_stat_queue(msg_get_queue(queueKeyCmdToAbeille))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyCmdToAbeille');
        if (msg_stat_queue(msg_get_queue(queueKeyCmdToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyCmdToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeyLQIToAbeille))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyLQIToAbeille');
        if (msg_stat_queue(msg_get_queue(queueKeyLQIToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyLQIToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeyXmlToAbeille))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyXmlToAbeille');
        if (msg_stat_queue(msg_get_queue(queueKeyXmlToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyXmlToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeyFormToCmd))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyFormToCmd');
        if (msg_stat_queue(msg_get_queue(queueKeySerieToParser))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeySerieToParser');
        if (msg_stat_queue(msg_get_queue(queueKeyParserToCmdSemaphore))["msg_qnum"] > 100) log::add('Abeille', 'info', 'deamon_info(): --------- ipcs queue too full: queueKeyParserToCmdSemaphore');

        if ($debug_deamon_info) log::add('Abeille', 'debug', 'deamon_info(): Terminé, return=' . json_encode($return));
        return $return;
    }

    /* This function is used before starting daemons to
           - run some cleanup
           - update the config database if changes needed
           Note: incorrect naming 'deamon' instead of 'daemon' due to Jeedom mistake. */
    public static function deamon_start_cleanup($message = null)
    {
        log::add('Abeille', 'debug', 'deamon_start_cleanup(): Démarrage');

        // ******************************************************************************************************************
        // Remove temporary files
        for ($i = 1; $i <= config::byKey('zigateNb', 'Abeille', '1', 1); $i++) {
            $lockFile = __DIR__ . '/../../tmp/AbeilleLQI_MapData' . $i . '.json.lock';
            if (file_exists($lockFile)) {
                unlink($lockFile);
                log::add('Abeille', 'debug', 'deamon_start_cleanup(): Suppression de ' . $lockFile);
            }
        }

        // Desactive les Zigate pour eviter de discuter avec une zigate sur le mauvais port
        // AbeilleIEEE_Ok = -1 si la Zigate detectée n est pas la bonne
        //     "          =  0 pour demarrer
        //     "          =  1 Si la zigate detectée est la bonne
        for ($i = 1; $i <= config::byKey('zigateNb', 'Abeille', '1', 1); $i++) {
            config::save("AbeilleIEEE_Ok" . $i, 0, 'Abeille');
        }

        /* Checking configuration DB version.
               If standard user, this should be done by installation process (install.php).
               If user based on GIT, this is a work-around */
        $dbVersion = config::byKey('DbVersion', 'Abeille', '');
        $dbVersionLast = 20201122;
        if (($dbVersion == '') || (intval($dbVersion) < $dbVersionLast)) {
            log::add('Abeille', 'debug', 'deamon_start_cleanup(): DB config v' . $dbVersion . ' < v' . $dbVersionLast . ' => Mise-à-jour');
            updateConfigDB();
        }

        log::add('Abeille', 'debug', 'deamon_start_cleanup(): Terminé');
        return;
    }

    /* Starting all daemons.
           Note: incorrect naming 'deamon' instead of 'daemon' due to Jeedom mistake. */
    public static function deamon_start($_debug = false)
    {

        log::add('Abeille', 'debug', 'deamon_start(): Démarrage');

        /* Developers debug features.
               Since deamon_start() is static, could not find a way to reuse global variables.
               WARNING: Since php file is cached, it sometimes requires delay or restart to
                 get last content of 'debug.php' */
        $dbgFile = __DIR__ . "/../../tmp/debug.php";
        if (file_exists($dbgFile))
            include $dbgFile;

        /* Some checks before starting daemons
               - Are dependancies ok ?
               - does Abeille cron exist ? */
        if (self::dependancy_info()['state'] != 'ok') {
            message::add("Abeille", "Tentative de demarrage alors qu il y a un soucis avec les dependances", "Avez vous installée les dépendances.");
            log::add('Abeille', 'debug', "Tentative de demarrage alors qu il y a un soucis avec les dependances");
            return false;
        }
        if (!is_object(cron::byClassAndFunction('Abeille', 'deamon'))) {
            log::add('Abeille', 'error', 'deamon_start(): Tache cron introuvable');
            message::add("Abeille", "deamon_start(): Tache cron introuvable", "Est ce un bug dans Abeille ?");
            throw new Exception(__('Tache cron introuvable', __FILE__));
        }

        self::deamon_stop();

        message::removeAll('Abeille');

        self::deamon_start_cleanup();

        $param = self::getParameters();

        /* Configuring GPIO for PiZigate if one active found.
               PiZigate reminder (using 'WiringPi'):
               - port 0 = RESET
               - port 2 = FLASH
               - Production mode: FLASH=1, RESET=0 then 1 */
        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if (($param['AbeilleSerialPort' . $i] == 'none') or ($param['AbeilleActiver' . $i] != 'Y'))
                continue; // Undefined or disabled
            if ($param['AbeilleType' . $i] == "PI") {
                AbeilleTools::checkGpio(); // Found an active PI Zigate, needed once
                break;
            }
        }

        //demarrage d'un cron pour le plugin.
        cron::byClassAndFunction('Abeille', 'deamon')->run();

        $parameters = self::getParameters();
        $running = AbeilleTools::getRunningDaemons();
        AbeilleTools::restartMissingDaemons($parameters, $running);

        // Send a message to Abeille to ask for Abeille Object creation: inclusion, ...
        log::add('Abeille', 'debug', 'deamon_start(): ***** Envoi de la creation de ruche par défaut ********');
        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if (($param['AbeilleSerialPort' . $i] == 'none') or ($param['AbeilleActiver' . $i] != 'Y'))
                continue; // Undefined or disabled

            log::add('Abeille', 'debug', 'deamon_start(): ***** ruche ' . $i . ' (Abeille): ' . basename($param['AbeilleSerialPort' . $i]));
            Abeille::publishMosquitto(queueKeyAbeilleToAbeille, priorityInterrogation, "CmdRuche/Ruche/CreateRuche", "Abeille" . $i);
            log::add('Abeille', 'debug', 'deamon_start(): ***** Demarrage du réseau Zigbee ' . $i . ' ********');
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "CmdAbeille" . $i . "/Ruche/startNetwork", "StartNetwork");
            log::add('Abeille', 'debug', 'deamon_start(): ***** Set Time réseau Zigbee ' . $i . ' ********');
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "CmdAbeille" . $i . "/Ruche/setTimeServer", "");
            log::add('Abeille', 'debug', 'deamon_start(): ***** getNetworkStatus ' . $i . ' ********');
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "CmdAbeille" . $i . "/Ruche/getNetworkStatus", "getNetworkStatus");
        }

        log::add('Abeille', 'debug', 'deamon_start(): Terminé');
        return true;
    }

    /**
     * Not used ?
     *
     * @param $Abeille
     * @return string
     */
    public static function mapAbeillePort($Abeille)
    {

        $param = self::getParameters();

        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if ($Abeille == "Abeille" . $i) return basename($param['AbeilleSerialPort' . $i]);
        }
    }

    /**
     * Not used ?
     *
     * @param $port
     * @return string
     */
    public static function mapPortAbeille($port)
    {

        $param = self::getParameters();

        for ($i = 1; $i <= $param['zigateNb']; $i++) {
            if ($port == $param['AbeilleSerialPort' . $i]) return "Abeille" . $i;
        }
    }

    /* Stopping all daemons and removing queues */
    public static function deamon_stop()
    {
        log::add('Abeille', 'debug', 'deamon_stop(): Démarrage');

        // Stop socat if exist
        // exec("ps -e -o '%p %a' --cols=10000 | awk '/socat /' | awk '/\/dev\/zigate/' | awk '{print $1}' | tr  '\n' ' '", $output);
        exec("ps -e -o '%p %a' --cols=10000 | awk '/socat /' | awk '/\/dev\/zigate/' | awk '{print $1}' | awk '{printf \"%s \",$0} END {print \"\"}'", $output);
        log::add('Abeille', 'debug', 'deamon_stop(): Killing deamons socat: ' . implode($output, '!'));
        system::kill(implode($output, ' '), true);
        exec(system::getCmdSudo() . "kill -15 " . implode($output, ' ') . " 2>&1");
        exec(system::getCmdSudo() . "kill -9 " . implode($output, ' ') . " 2>&1");

        /* Stop other deamons */
        exec("ps -e -o '%p %a' --cols=10000 | awk '/Abeille(Parser|SerialRead|Cmd|Socat|Interrogate|LQI).php /' | awk '{print $1}' | awk '{printf \"%s \",$0} END {print \"\"}'", $output);
        log::add('Abeille', 'debug', 'deamon stop: Killing deamons: ' . implode($output, '!'));
        system::kill(implode($output, ' '), true);
        exec(system::getCmdSudo() . "kill -15 " . implode($output, ' ') . " 2>&1");
        exec(system::getCmdSudo() . "kill -9 " . implode($output, ' ') . " 2>&1");

        // Stop main deamon
        log::add('Abeille', 'debug', 'deamon_stop(): Arret du cron');
        $cron = cron::byClassAndFunction('Abeille', 'deamon');
        if (is_object($cron)) {
            $cron->halt();
            // log::add('Abeille', 'error', 'deamon_stop(): demande d arret du cron faite');
        } else {
            log::add('Abeille', 'error', 'deamon_stop(): Tache cron introuvable');
        }

        msg_remove_queue(msg_get_queue(queueKeyAbeilleToAbeille));
        msg_remove_queue(msg_get_queue(queueKeyAbeilleToCmd));
        msg_remove_queue(msg_get_queue(queueKeyParserToAbeille));
        msg_remove_queue(msg_get_queue(queueKeyParserToCmd));
        msg_remove_queue(msg_get_queue(queueKeyParserToLQI));
        msg_remove_queue(msg_get_queue(queueKeyCmdToAbeille));
        msg_remove_queue(msg_get_queue(queueKeyCmdToCmd));
        msg_remove_queue(msg_get_queue(queueKeyLQIToAbeille));
        msg_remove_queue(msg_get_queue(queueKeyLQIToCmd));
        msg_remove_queue(msg_get_queue(queueKeyXmlToAbeille));
        msg_remove_queue(msg_get_queue(queueKeyXmlToCmd));
        msg_remove_queue(msg_get_queue(queueKeyFormToCmd));
        msg_remove_queue(msg_get_queue(queueKeySerieToParser));
        msg_remove_queue(msg_get_queue(queueKeyParserToCmdSemaphore));

        log::add('Abeille', 'debug', 'deamon_stop(): Terminé');
    }

    public static function dependancy_info()
    {

        log::add('Abeille', 'warning', '-------------------------------------> dependancy_info()');

        // Called by js dans plugin.class.js(getDependancyInfo) -> plugin.ajax.php(dependancy_info())
        // $dependancy_info['state'] pour affichage
        // state = [ok / nok / in_progress (progression/duration)] / state
        // il n ' y plus de dépendance hotmis pour la zigate wifi (socat) qui est installé par un script a part.
        $debug_dependancy_info = 1;

        $return = array();
        $return['state'] = 'ok';
        $return['progress_file'] = jeedom::getTmpFolder('Abeille') . '/dependance';

        // Check package socat
        $cmd = "command -v socat";
        exec($cmd, $output_dpkg, $return_var);
        if ($return_var == 1) {
            message::add("Abeille", "Le package socat est nécéssaire pour l'utilisation de la zigate Wifi. Si vous avez la zigate usb, vous pouvez ignorer ce message");
            log::add('Abeille', 'warning', 'Le package socat est nécéssaire pour l\'utilisation de la zigate Wifi.');
        }

        if ($debug_dependancy_info) log::add('Abeille', 'debug', 'dependancy_info: ' . json_encode($return));

        return $return;
    }

    public static function dependancy_install()
    {
        log::add('Abeille', 'debug', 'Installation des dépendances: IN');
        message::add("Abeille", "L installation des dependances est en cours", "N oubliez pas de lire la documentation: https://github.com/KiwiHC16/Abeille/tree/master/Documentation");
        log::remove(__CLASS__ . '_update');
        $result = ['script' => __DIR__ . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('Abeille') . '/dependance',
            'log' => log::getPathToLog(__CLASS__ . '_update')
        ];
        log::add('Abeille', 'debug', 'Installation des dépendances: OUT: ' . implode($result, ' X '));

        return $result;
    }

    public static function deamon()
    {

        try {
            $queueKeyAbeilleToAbeille = msg_get_queue(queueKeyAbeilleToAbeille);
            $queueKeyParserToAbeille = msg_get_queue(queueKeyParserToAbeille);
            $queueKeyCmdToAbeille = msg_get_queue(queueKeyCmdToAbeille);
            $queueKeyXmlToAbeille = msg_get_queue(queueKeyXmlToAbeille);

            $msg_type = NULL;
            $msg = NULL;
            $max_msg_size = 512;
            $message = new MsgAbeille;

            // https: github.com/torvalds/linux/blob/master/include/uapi/asm-generic/errno.h
            // #define    ENOMSG        42    /* No message of desired type */
            $errorcodeMsg = 0;

            while (true) {
                if (msg_receive($queueKeyAbeilleToAbeille, 0, $msg_type, $max_msg_size, $msg, true, MSG_IPC_NOWAIT, $errorcodeMsg)) {
                    $message->topic = $msg->message['topic'];
                    $message->payload = $msg->message['payload'];
                    self::message($message);
                    $msg_type = NULL;
                    $msg = NULL;
                }
                if (($errorcodeMsg != 42) and ($errorcodeMsg != 0)) {
                    log::add('Abeille', 'error', 'Erreur inattendue, lecture queue \'AbeilleToAbeille\': ' . $errorcodeMsg);
                }

                if (msg_receive($queueKeyParserToAbeille, 0, $msg_type, $max_msg_size, $msg, true, MSG_IPC_NOWAIT, $errorcodeMsg)) {
                    $message->topic = $msg->message['topic'];
                    $message->payload = $msg->message['payload'];
                    self::message($message);
                    $msg_type = NULL;
                    $msg = NULL;
                }
                if (($errorcodeMsg != 42) and ($errorcodeMsg != 0)) {
                    log::add('Abeille', 'debug', 'deamon fct: msg_receive queueKeyParserToAbeille issue: ' . $errorcodeMsg);
                }

                if (msg_receive($queueKeyCmdToAbeille, 0, $msg_type, $max_msg_size, $msg, true, MSG_IPC_NOWAIT, $errorcodeMsg)) {
                    $message->topic = $msg->message['topic'];
                    $message->payload = $msg->message['payload'];
                    self::message($message);
                    $msg_type = NULL;
                    $msg = NULL;
                }
                if (($errorcodeMsg != 42) and ($errorcodeMsg != 0)) {
                    log::add('Abeille', 'debug', 'deamon fct: msg_receive queueKeyCmdToAbeille issue: ' . $errorcodeMsg);
                }

                if (msg_receive($queueKeyXmlToAbeille, 0, $msg_type, $max_msg_size, $msg, true, MSG_IPC_NOWAIT, $errorcodeMsg)) {
                    $message->topic = $msg->message['topic'];
                    $message->payload = $msg->message['payload'];
                    self::message($message);
                    $msg_type = NULL;
                    $msg = NULL;
                }
                if (($errorcodeMsg != 42) and ($errorcodeMsg != 0)) {
                    log::add('Abeille', 'debug', 'deamon fct: msg_receive queueKeyXmlToAbeille issue: ' . $errorcodeMsg);
                }

                time_nanosleep(0, 10000000); // 1/100s
            }

        } catch (Exception $e) {
            log::add('Abeille', 'error', 'Gestion erreur dans boucle try: ' . $e->getMessage());
        }

    }

    public static function getParameters()
    {

        $return = array();
        $return['parametersCheck'] = 'ok'; // Ces deux variables permettent d'indiquer la validité des données.
        $return['parametersCheck_message'] = "";

        //Most Fields are defined with default values
        $return['AbeilleParentId'] = config::byKey('AbeilleParentId', 'Abeille', '1', 1);
        $return['zigateNb'] = config::byKey('zigateNb', 'Abeille', '1', 1);

        for ($i = 1; $i <= $return['zigateNb']; $i++) {
            $return['AbeilleType' . $i] = config::byKey('AbeilleType' . $i, 'Abeille', 'none', 1);
            $return['AbeilleSerialPort' . $i] = config::byKey('AbeilleSerialPort' . $i, 'Abeille', 'none', 1);
            $return['IpWifiZigate' . $i] = config::byKey('IpWifiZigate' . $i, 'Abeille', '192.168.0.1', 1);
            $return['AbeilleActiver' . $i] = config::byKey('AbeilleActiver' . $i, 'Abeille', 'N', 1);
        }

        return $return;
    }

    // public static function checkParameters() {
    //     // return 1 si Ok, 0 si erreur
    //     $param = Abeille::getParameters();

    //     if ( !isset($param['zigateNb']) ) { return 0; }
    //     if ( $param['zigateNb'] < 1 ) { return 0; }
    //     if ( $param['zigateNb'] > 9 ) { return 0; }

    //     // Testons la validité de la configuration
    //     $atLeastOneZigateActiveWithOnePortDefined = 0;
    //     for ( $i=1; $i<=$param['zigateNb']; $i++ ) {
    //         if ($return['AbeilleActiver'.$i ]=='Y') {
    //             if ($return['AbeilleSerialPort'.$i]!='none') {
    //                 $atLeastOneZigateActiveWithOnePortDefined++;
    //             }
    //         }
    //     }
    //     if ( $atLeastOneZigateActiveWithOnePortDefined <= 0 ) {
    //         log::add('Abeille','debug','checkParameters: aucun serialPort n est pas défini/actif.');
    //         message::add('Abeille','Warning: Aucun port série n est pas défini/Actif dans la configuration.' );
    //         return 0;
    //     }

    //     // Vérifions l existence des ports
    //     for ( $i=1; $i<=$param['zigateNb']; $i++ ) {
    //         if ($return['AbeilleActiver'.$i ]=='Y') {
    //             if ($return['AbeilleSerialPort'.$i] != 'none') {
    //                 if (@!file_exists($return['AbeilleSerialPort'.$i])) {
    //                     log::add('Abeille','debug','checkParameters: Le port série n existe pas: '.$return['AbeilleSerialPort'.$i]);
    //                     message::add('Abeille','Warning: Le port série n existe pas: '.$return['AbeilleSerialPort'.$i],'' );
    //                     $return['parametersCheck']="nok";
    //                     $return['parametersCheck_message'] = __('Le port série '.$return['AbeilleSerialPort'.$i].' n existe pas (zigate déconnectée ?)', __FILE__);
    //                     return 0;
    //                 } else {
    //                     if (substr(decoct(fileperms($return['AbeilleSerialPort'.$i])), -4) != "0777") {
    //                         exec(system::getCmdSudo().'chmod 777 '.$return['AbeilleSerialPort'.$i].' > /dev/null 2>&1');
    //                     }
    //                 }
    //             }
    //         }
    //     }

    //     return 1;
    // }

    public static function postSave()
    {
        // log::add('Abeille', 'debug', 'deamon_postSave: IN');
        $cron = cron::byClassAndFunction('Abeille', 'deamon');
        if (is_object($cron) && !$cron->running()) {
            $cron->run();
        }
        // log::add('Abeille', 'debug', 'deamon_postSave: OUT');
    }

    public static function fetchShortFromIEEE($IEEE, $checkShort)
    {
        // Return:
        // 0 : Short Address is aligned with the one received
        // Short : Short Address is NOT aligned with the one received
        // -1 : Error Nothing found

        // $lookForIEEE = "000B57fffe490C2a";
        // $checkShort = "2006";
        // log::add('Abeille', 'debug', 'KIWI: start function fetchShortFromIEEE');
        $abeilles = Abeille::byType('Abeille');

        foreach ($abeilles as $abeille) {

            if (strlen($abeille->getConfiguration('IEEE', 'none')) == 16) {
                $IEEE_abeille = $abeille->getConfiguration('IEEE', 'none');
            } else {
                $cmdIEEE = $abeille->getCmd('Info', 'IEEE-Addr');
                if (is_object($cmdIEEE)) {
                    $IEEE_abeille = $cmdIEEE->execCmd();
                    if (strlen($IEEE_abeille) == 16) {
                        $abeille->setConfiguration('IEEE', $IEEE_abeille); // si j ai l IEEE dans la cmd et pas dans le conf, je transfer, retro compatibility
                        $abeille->save();
                        $abeille->refresh();
                    }
                }
            }

            if ($IEEE_abeille == $IEEE) {

                $cmdShort = $abeille->getCmd('Info', 'Short-Addr');
                if ($cmdShort) {
                    if ($cmdShort->execCmd() == $checkShort) {
                        // echo "Success ";
                        // log::add('Abeille', 'debug', 'KIWI: function fetchShortFromIEEE return 0');
                        return 0;
                    } else {
                        // echo "Pas success du tout ";
                        // La cmd short n est pas forcement à jour alors on va essayer avec le nodeId.
                        // log::add('Abeille', 'debug', 'KIWI: function fetchShortFromIEEE return Short: '.$cmdShort->execCmd() );
                        // return $cmdShort->execCmd();
                        // log::add('Abeille', 'debug', 'KIWI: function fetchShortFromIEEE return Short: '.substr($abeille->getlogicalId(),-4) );
                        return substr($abeille->getlogicalId(), -4);
                    }

                    return $return;
                }

            }
        }

        // log::add('Abeille', 'debug', 'KIWI: function fetchShortFromIEEE return -1');
        return -1;
    }

    public static function checkInclusionStatus($dest)
    {
        // Return: Inclusion status or -1 if error
        $ruche = Abeille::byLogicalId($dest . '/Ruche', 'Abeille');

        if ($ruche) {
            // echo "Join status collection\n";
            $cmdJoinStatus = $ruche->getCmd('Info', 'permitJoin-Status');
            if ($cmdJoinStatus) {
                return $cmdJoinStatus->execCmd();
            }
        }

        return -1;
    }

    public static function CmdAffichage($affichageType, $Visibility = "na")
    {
        // $affichageType could be:
        //  affichageNetwork
        //  affichageTime
        //  affichageCmdAdd
        // $Visibilty command could be
        // Y
        // N
        // toggle
        // na

        if ($Visibility == "na") {
            return;
        }

        $parameters_info = self::getParameters();

        $convert = array(
            "affichageNetwork" => "Network",
            "affichageTime" => "Time",
            "affichageCmdAdd" => "additionalCommand"
        );

        log::add('Abeille', 'debug', 'Entering CmdAffichage with affichageType: ' . $affichageType . ' - Visibility: ' . $Visibility);
        echo 'Entering CmdAffichage with affichageType: ' . $affichageType . ' - Visibility: ' . $Visibility;

        switch ($Visibility) {
            case 'Y':
                break;
            case 'N':
                break;
            case 'toggle':
                if ($parameters_info[$affichageType] == 'Y') {
                    $Visibility = 'N';
                } else {
                    $Visibility = 'Y';
                }
                break;
        }
        config::save($affichageType, $Visibility, 'Abeille');

        $abeilles = self::byType('Abeille');
        foreach ($abeilles as $key => $abeille) {
            $cmds = $abeille->getCmd();
            foreach ($cmds as $keyCmd => $cmd) {
                if ($cmd->getConfiguration("visibilityCategory") == $convert[$affichageType]) {
                    switch ($Visibility) {
                        case 'Y':
                            $cmd->setIsVisible(1);
                            break;
                        case 'N':
                            $cmd->setIsVisible(0);
                            break;
                    }
                }
                $cmd->save();
            }
            $abeille->save();
            $abeille->refresh();
        }

        log::add('Abeille', 'debug', 'Leaving CmdAffichage');
        return;
    }

    public static function message($message)
    {

        // log::add('Abeille', 'debug', "message(topic='".$message->topic."', payload='".$message->payload."')");

        $topicArray = explode("/", $message->topic);
        if (sizeof($topicArray) != 3) {
            log::add('Abeille', 'error', "Le topic n'a pas 3 éléments: " . $message->topic);
            return;
        }

        $parameters_info = self::getParameters();

        // if (!preg_match("(Time|Link-Quality)", $message->topic)) {
        // log::add('Abeille', 'debug', "fct message Topic: ->".$message->topic."<- Value ->".$message->payload."<-");
        // }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // demande de creation de ruche au cas ou elle n'est pas deja crée....
        // La ruche est aussi un objet Abeille
        if ($message->topic == "CmdRuche/Ruche/CreateRuche") {
            // log::add('Abeille', 'debug', "Topic: ->".$message->topic."<- Value ->".$message->payload."<-");
            self::createRuche($message);
            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // On ne prend en compte que les message Abeille|Ruche|CmdCreate/#/#
        // CmdCreate -> pour la creation des objets depuis la ruche par exemple pour tester les modeles
        if (!preg_match("(^Abeille|^Ruche|^CmdCreate|^ttyUSB|^zigate|^monitZigate)", $message->topic)) {
            // log::add('Abeille', 'debug', 'message: this is not a ' . $Filter . ' message: topic: ' . $message->topic . ' message: ' . $message->payload);
            return;
        }

        /*----------------------------------------------------------------------------------------------------------------------------------------------*/
        // Analyse du message recu
        // [CmdAbeille:Abeille] / Address / Cluster-Parameter
        // [CmdAbeille:Abeille] / $addr / $cmdId => $value
        // $nodeId = [CmdAbeille:Abeille] / $addr

        list($Filter, $addr, $cmdId) = explode("/", $message->topic);
        if (preg_match("(^CmdCreate)", $message->topic)) {
            $Filter = str_replace("CmdCreate", "", $Filter);
        }
        $dest = $Filter;

        // log all messages except the one related to Time, which overload the log
        if (!in_array($cmdId, array("Time-Time", "Time-TimeStamp", "Link-Quality"))) {
            log::add('Abeille', 'debug', "message(topic='" . $message->topic . "', payload='" . $message->payload . "')");
        }

        // Si le message est pour 0000 alors on change en Ruche
        if ($addr == "0000") $addr = "Ruche";

        $nodeid = $Filter . '/' . $addr;

        $value = $message->payload;

        // Le capteur de temperature rond V1 xiaomi envoie spontanement son nom: ->lumi.sensor_ht<- mais envoie ->lumi.sens<- sur un getName
        if ($cmdId == "0000-01-0005") {
            if ($value == "lumi.sens") {
                $value = "lumi.sensor_ht";
                message::add("Abeille", "lumi.sensor_ht case tracking: " . json_encode($message), '');
            }
            if ($value == "lumi.sensor_swit") $value = "lumi.sensor_switch.aq3";
            if ($value == "TRADFRI Signal Repeater") $value = "TRADFRI signal repeater";
        }
        $type = 'topic';         // type = topic car pas json

        // Si cmd activate/desactivate NE based on IEEE Leaving/Joining
        if (($cmdId == "enable") || ($cmdId == "disable")) {
            log::add('Abeille', 'debug', 'Entering enable/disable: ' . $cmdId);

            $abeilles = self::byType('Abeille');
            foreach ($abeilles as $key => $abeille) {
                $done = 0;

                if ($abeille->getConfiguration('IEEE', 'none') == $value) {
                    if ($cmdId == "enable") {
                        $abeille->setIsEnable(1);
                    } else {
                        $abeille->setIsEnable(0);
                        message::add("Abeille", "Equipement '" . $abeille->getName() . "' désactivé. Il a probablement quitté le réseau.", '');
                    }
                    $abeille->save();
                    $abeille->refresh();

                    $done = 1;
                    }

                if (!$done) {
                    $cmds = Cmd::byLogicalId('IEEE-Addr');
                    foreach ($cmds as $cmd) {
                        if ($cmd->execCmd() == $value) {
                            $abeille = $cmd->getEqLogic();
                            if ($cmdId == "enable") {
                                $abeille->setIsEnable(1);
                            } else {
                                $abeille->setIsEnable(0);
                            }
                            $abeille->save();
                            $abeille->refresh();
                        }
                        echo "\n";
                    }
                }
                            }
            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Cherche l objet par sa ref short Address et la commande

        // log::add('Abeille', 'debug', 'Looking for nodeId: '.$nodeid );
        $elogic = self::byLogicalId($nodeid, 'Abeille');

        // log::add('Abeille', 'debug', 'Looking for cmd of nodeId: '.$cmdId );
        if (is_object($elogic)) {
            $cmdlogic = AbeilleCmd::byEqLogicIdAndLogicalId($elogic->getId(), $cmdId);
        }

        // log::add('Abeille', 'debug', 'I should have the cmd now' );

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Si l objet n existe pas et je recoie son ManufacturerName => Je concerve dans l attente de son nom pour la creation.
        if (!is_object($elogic) && (preg_match("/^0000-[0-9A-Fa-f]*-*0004/", $cmdId)) ) {
            
            $trimmedValue = $value;
            $trimmedValue = str_replace(' ', '', $trimmedValue); //remove all space in names for easier filename handling
            $trimmedValue = str_replace("\0", '', $trimmedValue); // On enleve les 0x00 comme par exemple le nom des equipements Legrand
            
            $this->ManufacturerNameTable[] = array( $nodeid => array ( 'time'=> time(), 'ManufacturerName'=>$trimmedValue ) );
                
        }
        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Si l objet n existe pas et je recoie son nom => je créé l objet.
        if (!is_object($elogic)
            && (preg_match("/^0000-[0-9A-Fa-f]*-*0005/", $cmdId)
                || preg_match("/^0000-[0-9A-Fa-f]*-*0010/", $cmdId)
                || preg_match("/^SimpleDesc-[0-9A-Fa-f]*-*DeviceDescription/", $cmdId)
            )
        ) {

            $trimmedValue = $value;
            log::add('Abeille', 'info', 'Recherche objet: ' . $value . ' dans les objets connus');

            /* Remove leading "lumi." from name as all xiaomi devices who start with this prefix. */
            if (!strncasecmp($trimmedValue, "lumi.", 5))
                $trimmedValue = substr($trimmedValue, 5); // Remove leading "lumi." case insensitive

            //remove all space in names for easier filename handling
            $trimmedValue = str_replace(' ', '', $trimmedValue);

            // On enleve le / comme par exemple le nom des equipements Legrand
            $trimmedValue = str_replace('/', '', $trimmedValue);

            // On enleve les 0x00 comme par exemple le nom des equipements Legrand
            $trimmedValue = str_replace("\0", '', $trimmedValue);

            log::add('Abeille', 'debug', 'value:' . $value . ' / trimmed value: ->' . $trimmedValue . '<-');
            $AbeilleObjetDefinition = AbeilleTools::getJSonConfigFilebyDevicesTemplate($trimmedValue);
            log::add('Abeille', 'debug', 'Template initial: ' . json_encode($AbeilleObjetDefinition));

            // On recupere le EP
            // $EP = substr($cmdId,5,2);
            $EP = explode('-', $cmdId)[1];
            log::add('Abeille', 'debug', 'EP: ' . $EP);
            $AbeilleObjetDefinitionJson = json_encode($AbeilleObjetDefinition);
            $AbeilleObjetDefinitionJson = str_replace('#EP#', $EP, $AbeilleObjetDefinitionJson);
            $AbeilleObjetDefinition = json_decode($AbeilleObjetDefinitionJson, true);
            log::add('Abeille', 'debug', 'Template mis a jour avec EP: ' . json_encode($AbeilleObjetDefinition));

            if (array_key_exists($trimmedValue, $AbeilleObjetDefinition)) {
                $jsonName = $trimmedValue;
            }
            if (array_key_exists('defaultUnknown', $AbeilleObjetDefinition)) {
                $jsonName = 'defaultUnknown';
            }

            /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
            // Creation de l objet Abeille
            // Exemple pour les objets créés par les commandes Ruche.
            if (strlen($addr) != 4) {
                $index = rand(1000, 9999);
                $addr = $addr . "-" . $index;
                $nodeid = $nodeid . "-" . $index;
            }

            $elogic = new Abeille();
            $elogic->setEqType_name('Abeille');
            $elogic->setName("nouveau-".$addr); // Temp name to have it non empty
            $elogic->save(); // Save to force Jeedom to assign an ID

            $name = $Filter."-".$elogic->getId();
            message::add("Abeille", "Nouvel équipement détecté: ".$name.". Création en cours. Rafraîchissez votre dashboard dans qq secondes.", '');
            $elogic->setName($name);
            $elogic->setLogicalId($nodeid);
            $elogic->setObject_id($parameters_info['AbeilleParentId']);
            $objetDefSpecific = $AbeilleObjetDefinition[$jsonName];
            $objetConfiguration = $objetDefSpecific["configuration"];
            log::add('Abeille', 'debug', 'Template configuration: ' . json_encode($objetConfiguration));
            $elogic->setConfiguration('modeleJson', $trimmedValue);
            $elogic->setConfiguration('topic', $nodeid);
            $elogic->setConfiguration('type', $type);
            $elogic->setConfiguration('uniqId', $objetConfiguration["uniqId"]);
            $elogic->setConfiguration('icone', $objetConfiguration["icone"]);
            $elogic->setConfiguration('mainEP', $objetConfiguration["mainEP"]);
            $lastCommTimeout = (array_key_exists("lastCommunicationTimeOut", $objetConfiguration) ? $objetConfiguration["lastCommunicationTimeOut"] : '-1');
            $elogic->setConfiguration('lastCommunicationTimeOut', $lastCommTimeout);
            $elogic->setConfiguration('type', $type);

            if (isset($objetConfiguration['battery_type'])) {
                $elogic->setConfiguration('battery_type', $objetConfiguration['battery_type']);
            }
            if (isset($objetConfiguration['Groupe'])) {
                $elogic->setConfiguration('Groupe', $objetConfiguration['Groupe']);
            }
            if (isset($objetConfiguration['onTime'])) {
                $elogic->setConfiguration('onTime', $objetConfiguration['onTime']);
            }
            if (isset($objetConfiguration['Zigate'])) {
                $elogic->setConfiguration('Zigate', $objetConfiguration['Zigate']);
            }
            if (isset($objetConfiguration['protocol'])) {
                $elogic->setConfiguration('protocol', $objetConfiguration['protocol']);
            }
            if (isset($objetConfiguration['poll'])) {
                $elogic->setConfiguration('poll', $objetConfiguration['poll']);
            }
            $elogic->setIsVisible("1");

            // eqReal_id
            $elogic->setIsEnable("1");
            // status
            // timeout
            $elogic->setTimeout($objetDefSpecific["timeout"]);

            $elogic->setCategory(array_keys($objetDefSpecific["Categorie"])[0], $objetDefSpecific["Categorie"][array_keys($objetDefSpecific["Categorie"])[0]]);
            // display
            // order
            // comment

            //log::add('Abeille', 'info', 'Saving device ' . $nodeid);
            //$elogic->save();
            $elogic->setStatus('lastCommunication', date('Y-m-d H:i:s'));
            $elogic->save();

            // Creation des commandes pour l objet Abeille juste créé.
            if (isset($GLOBALS['debugKIWI']) && $GLOBALS['debugKIWI']) {
                echo "On va creer les commandes.\n";
                print_r($objetDefSpecific['Commandes']);
            }

            foreach ($objetDefSpecific['Commandes'] as $cmd => $cmdValueDefaut) {
                log::add('Abeille', 'info', 'Creation de la commande: ' . $cmd . ' suivant model de l objet pour l objet: ' . $name);
                // 'Creation de la commande: ' . $nodeid . '/' . $cmd . ' suivant model de l objet pour l objet: ' . $name

                $cmdlogic = new AbeilleCmd();
                // id
                $cmdlogic->setEqLogic_id($elogic->getId());
                $cmdlogic->setEqType('Abeille');
                $cmdlogic->setLogicalId($cmd);
                $cmdlogic->setOrder($cmdValueDefaut["order"]);
                $cmdlogic->setName($cmdValueDefaut["name"]);
                // value

                if ($cmdValueDefaut["Type"] == "info") {
                    // $cmdlogic->setConfiguration('topic', $nodeid . '/' . $cmd);
                    $cmdlogic->setConfiguration('topic', $cmd);
                }
                if ($cmdValueDefaut["Type"] == "action") {
                    $cmdlogic->setConfiguration('retain', '0');

                    if (isset($cmdValueDefaut["value"])) {
                        // value: pour les commandes action, contient la commande info qui est la valeur actuel de la variable controlée.
                        log::add('Abeille', 'debug', 'Define cmd info pour cmd action: ' . $elogic->getName() . " - " . $cmdValueDefaut["value"]);

                        $cmdPointeur_Value = cmd::byTypeEqLogicNameCmdName("Abeille", $elogic->getName(), $cmdValueDefaut["value"]);
                        $cmdlogic->setValue($cmdPointeur_Value->getId());
                    }
                }

                // La boucle est pour info et pour action
                foreach ($cmdValueDefaut["configuration"] as $confKey => $confValue) {
                    // Pour certaine Action on doit remplacer le #addr# par la vrai valeur
                    $cmdlogic->setConfiguration($confKey, str_replace('#addr#', $addr, $confValue));

                    // Ne pas effacer, en cours de dev.
                    // $cmdlogic->setConfiguration($confKey, str_replace('#addrIEEE#',     '#addrIEEE#',   $confValue));
                    // $cmdlogic->setConfiguration($confKey, str_replace('#ZiGateIEEE#',   '#ZiGateIEEE#', $confValue));
                }
                // On conserve l info du template pour la visibility
                $cmdlogic->setConfiguration("visibiltyTemplate", $cmdValueDefaut["isVisible"]);

                // template
                $cmdlogic->setTemplate('dashboard', $cmdValueDefaut["template"]);
                $cmdlogic->setTemplate('mobile', $cmdValueDefaut["template"]);
                $cmdlogic->setIsHistorized($cmdValueDefaut["isHistorized"]);
                $cmdlogic->setType($cmdValueDefaut["Type"]);
                $cmdlogic->setSubType($cmdValueDefaut["subType"]);
                if (array_key_exists("generic_type", $cmdValueDefaut))
                    $cmdlogic->setGeneric_type($cmdValueDefaut["generic_type"]);
                // unite
                if (isset($cmdValueDefaut["unite"])) {
                    $cmdlogic->setUnite($cmdValueDefaut["unite"]);
                }

                if (isset($cmdValueDefaut["invertBinary"])) {
                    $cmdlogic->setDisplay('invertBinary', $cmdValueDefaut["invertBinary"]);
                }
                // La boucle est pour info et pour action
                // isVisible
                $parameters_info = self::getParameters();
                $isVisible = $cmdValueDefaut["isVisible"];

                if (array_key_exists("display", $cmdValueDefaut))
                    foreach ($cmdValueDefaut["display"] as $confKey => $confValue) {
                        // Pour certaine Action on doit remplacer le #addr# par la vrai valeur
                        $cmdlogic->setDisplay($confKey, $confValue);
                    }

                $cmdlogic->setIsVisible($isVisible);

                // html
                // alert

                $cmdlogic->save();

                // $elogic->checkAndUpdateCmd( $cmdlogic, $cmdValueDefaut["value"] );

                if ($cmdlogic->getName() == "Short-Addr") {
                    $elogic->checkAndUpdateCmd($cmdlogic, $addr);
                }
            }

            // On defini le nom de l objet
            $elogic->checkAndUpdateCmd($cmdId, $value);

            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        /* If unknown eq and IEEE received, looking for eq with same IEEE to update logicalName & topic */
        // e.g. Short address change (Si l adresse a changé, on ne peut pas trouver l objet par son nodeId)
        if (!is_object($elogic) && ($cmdId == "IEEE-Addr")) {
            log::add('Abeille', 'debug', 'message(), !objet & IEEE: Recherche de l\'equipement correspondant');
            // 0 : Short Address is aligned with the one received
            // Short : Short Address is NOT aligned with the one received
            // -1 : Error Nothing found
            $ShortFound = Abeille::fetchShortFromIEEE($value, $addr);
            log::add('Abeille', 'debug', 'message(), !objet & IEEE: Trouvé='.$ShortFound);
            if ((strlen($ShortFound) == 4) && ($addr != "Ruche")) {

                $elogic = self::byLogicalId($dest."/".$ShortFound, 'Abeille');
                if (!is_object($elogic)) {
                    log::add('Abeille', 'debug', 'message(), !objet & IEEE: L\'équipement ne semble pas sur la bonne zigate. Abeille ne fait rien automatiquement. L\'utilisateur doit résoudre la situation.');
                    return;
                }

                // log::add('Abeille', 'debug', "message(), !objet & IEEE: Adresse IEEE $value pour $addr qui remonte est deja dans l objet $ShortFound - " . $elogic->getName() . ", on fait la mise a jour automatique");
                log::add('Abeille', 'debug', "message(), !objet & IEEE: $value correspond à '".$elogic->getName()."'. Mise-à-jour de l'adresse courte $ShortFound vers $addr.");
                // Comme c est automatique des que le retour d experience sera suffisant, on n alerte pas l utilisateur. Il n a pas besoin de savoir
                // message::add("Abeille", "IEEE-Addr; adresse IEEE $value pour $addr qui remonte est deja dans l objet $ShortFound - " . $elogic->getName() . ", on fait la mise a jour automatique", '');
                message::add("Abeille", "Nouvelle adresse '".$addr."' pour '".$elogic->getName()."'. Mise à jour automatique.");

                // Si on trouve l adresse dans le nom, on remplace par la nouvelle adresse
                // log::add('Abeille', 'debug', "!objet&IEEE --> IEEE-Addr; Ancien nom: " . $elogic->getName() . ", nouveau nom: " . str_replace($ShortFound, $addr, $elogic->getName()));
                // $elogic->setName(str_replace($ShortFound, $addr, $elogic->getName()));

                $elogic->setLogicalId($dest."/".$addr);
                $elogic->setConfiguration('topic', $dest."/".$addr);
                $elogic->save();

                // Il faut aussi mettre a jour la commande short address
                Abeille::publishMosquitto(queueKeyAbeilleToAbeille, priorityInterrogation, $dest."/".$addr."/Short-Addr", $addr);
            } else {
                log::add('Abeille', 'debug', 'message(), !objet & IEEE: Je n ai pas trouvé d Abeille qui corresponde, je ne fais rien.');
            }
            // log::add('Abeille', 'debug', '!objet&IEEE --> fin du traitement');
            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Si l objet n existe pas et je recoie une commande => je drop la cmd
        // e.g. un Equipement envoie des infos, mais l objet n existe pas dans Jeedom
        if (!is_object($elogic)) {
            log::add('Abeille', 'debug', "L equipement " . $dest . "/" . $addr . " n existe pas dans Jeedom, je ne process pas la commande, j'essaye d interroger l equipement pour le créer.");
            // Abeille::publishMosquitto( queueKeyAbeilleToCmd, priorityNeWokeUp, $dest."/".$addr."/Short-Addr", $addr );
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd" . $dest . "/" . $addr . "/Annonce", "Default");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd" . $dest . "/" . $addr . "/Annonce", "Hue");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd" . $dest . "/" . $addr . "/Annonce", "OSRAM");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityNeWokeUp, "Cmd" . $dest . "/" . $addr . "/AnnonceProfalux", "Default");
            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Si l objet exist et on recoie une IEEE
        // e.g. Un NE renvoie son annonce
        if (is_object($elogic) && ($cmdId == "IEEE-Addr")) {

            // Je rejete les valeur null (arrive avec les equipement xiaomi qui envoie leur nom spontanement alors que l IEEE n est pas recue.
            if (strlen($value) < 2) {
                log::add('Abeille', 'debug', 'IEEE-Addr; =>' . $value . '<= ; IEEE non valable pour un equipement, valeur rejetée: ' . $addr . ": IEEE =>" . $value . "<=");
                return;
            }

            // ffffffffffffffff remonte avec les mesures LQI si nouveau equipements.
            if ($value == "FFFFFFFFFFFFFFFF") {
                log::add('Abeille', 'debug', 'IEEE-Addr; =>' . $value . '<= ; IEEE non valable pour un equipement, valeur rejetée: ' . $addr . ": IEEE =>" . $value . "<=");
                return;
            }

            // Update IEEE cmd
            if (!is_object($cmdlogic)) {
                log::add('Abeille', 'debug', 'IEEE-Addr commande n existe pas');
                return;
            }

            $IEEE = $cmdlogic->execCmd();
            if ($IEEE == $value) {
                // log::add('Abeille', 'debug', 'IEEE-Addr;'.$value.';Ok pas de changement de l adresse IEEE, je ne fais rien.' );
                $elogic->checkAndUpdateCmd($cmdlogic, $value); // -> Je fais quand meme la mise a jour pour avoir tous les IEEE en mininuscule qui soit mis a jour en majuscule.
                $elogic->setConfiguration('IEEE', $value);
                $elogic->save();
                $elogic->refresh();
                return;
            }

            // Je ne sais pas pourquoi des fois on recoit des IEEE null
            if ($value == "0000000000000000") {
                log::add('Abeille', 'debug', 'IEEE-Addr;' . $value . ';IEEE recue est null, je ne fais rien.');
                return;
            }

            // Je ne fais pas d alerte dans le cas ou IEEE est null car pas encore recupere du réseau.
            if (strlen($IEEE) > 2) {
                log::add('Abeille', 'debug', 'IEEE-Addr;' . $value . ';Alerte changement de l adresse IEEE pour un equipement !!! ' . $addr . ": " . $IEEE . " =>" . $value . "<=");
                message::add("Abeille", "Alerte changement de l adresse IEEE pour un equipement !!! ( $addr : $IEEE =>$value<= )", '');
            }

            $elogic->checkAndUpdateCmd($cmdlogic, $value);
            $elogic->setConfiguration('IEEE', $value);
            $elogic->save();
            $elogic->refresh();

            return;
        }

        /*-----------------------------------------------------------------------------------------------------------------------------------------------------------------------*/
        // Si equipement et cmd existe alors on met la valeur a jour
        if (is_object($elogic) && is_object($cmdlogic)) {
            /* Traitement particulier pour les batteries */
            if ($cmdId == "Batterie-Volt") {
                /* Volt en milli V. Max a 3,1V Min a 2,7V, stockage en % batterie */
                $elogic->setStatus('battery', self::volt2pourcent($value));
                $elogic->setStatus('batteryDatetime', date('Y-m-d H:i:s'));
            }
            if ($cmdId == "Batterie-Pourcent") {
                $elogic->setStatus('battery', $value);
                $elogic->setStatus('batteryDatetime', date('Y-m-d H:i:s'));
            }
            if ($cmdId == "0001-01-0021") {
                /* en % batterie example Ikea Remote */
                // 10.10.2.1   BatteryPercentageRemaining Attribute Specifies the remaining battery life as a half integer percentage of the full battery capacity (e.g.
                // 34.5%, 45%, 68.5%, 90%) with a range between zero and 100%, with 0x00 = 0%, 0x64 = 50%, and 0xC8 = 100%. This is particularly suited for devices with
                // rechargeable batteries. The value 0xff indicates an invalid or unknown reading. This attribute SHALL be configurable for attribute reporting.
                // C8 is 200, so value/200*100
                $elogic->setStatus('battery', $value / 2);
                $elogic->setStatus('batteryDatetime', date('Y-m-d H:i:s'));
            }

            /*
                if ( ($cmdId == "Zigate-8000") && (substr($value,0,2)!="00") ) {
                    message::add( "Abeille", "La Zigate semble ne pas pouvoir traiter toutes les demandes.",'KiwiHC16: Investigations en cours pour mieux traiter ce sujet.' );
                }
                */

            /* Traitement particulier pour la remontée de nom qui est utilisé pour les ping des routeurs */
            // if (($cmdId == "0000-0005") || ($cmdId == "0000-0010")) {
            if (preg_match("/^0000-[0-9A-F]*-*0005/", $cmdId) || preg_match("/^0000-[0-9A-F]*-*0010/", $cmdId)) {
                log::add('Abeille', 'debug', 'Update ONLINE Status');
                $cmdlogicOnline = AbeilleCmd::byEqLogicIdAndLogicalId($elogic->getId(), 'online');
                $elogic->checkAndUpdateCmd($cmdlogicOnline, 1);
            }

            // Traitement particulier pour rejeter certaines valeurs
            // exemple: le Xiaomi Wall Switch 2 Bouton envoie un On et un Off dans le même message donc Abeille recoit un ON/OFF consecutif et
            // ne sais pas vraiment le gérer donc ici on rejete le Off et on met un retour d'etat dans la commande Jeedom
            if ($cmdlogic->getConfiguration('AbeilleRejectValue', -9999.99) == $value) {
                log::add('Abeille', 'debug', 'Rejet de la valeur: ' . $cmdlogic->getConfiguration('AbeilleRejectValue', -9999.99) . ' - ' . $value);

                return;
            }

            $elogic->checkAndUpdateCmd($cmdlogic, $value);

            // Polling to trigger based on this info cmd change: e.g. state moved to On, getPower value.
            $cmds = AbeilleCmd::searchConfigurationEqLogic($elogic->getId(), 'PollingOnCmdChange', 'action');

            foreach ($cmds as $key => $cmd) {
                if ($cmd->getConfiguration('PollingOnCmdChange') == $cmdId) {
                    log::add('Abeille', 'debug', 'Cmd action execution: ' . $cmd->getName());
                    // $cmd->execute(); si j'envoie la demande immediatement le device n a pas le temps de refaire ses mesures et repond avec les valeurs d avant levenement
                    // Je vais attendre qq secondes aveant de faire la demande
                    // Abeille::publishMosquitto( queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd".$dest."/".$address."/ReadAttributeRequest&time=".(time()+2), "EP=".$eqLogic->getConfiguration('mainEP')."&clusterId=0006&attributeId=0000" );
                    Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityInterrogation, "TempoCmd" . $cmd->getEqLogic()->getLogicalId() . "/" . $cmd->getConfiguration('topic') . "&time=" . (time() + $cmd->getConfiguration('PollingOnCmdChangeDelay')), $cmd->getConfiguration('request'));
                }
            }

            return;
        }

        if (is_object($elogic) && !is_object($cmdlogic)) {
            log::add('Abeille', 'debug', "  L'objet '" . $nodeid . "' existe mais pas la cmde '" . $cmdId . "' => message ignoré");
            return;
        }
        log::add('Abeille', 'debug', "Tres bizarre, Message non traité, il manque probablement du code.");

        return; // function message
    }

    public static function publishMosquitto($queueId, $priority, $topic, $payload)
    {
        $parameters_info = Abeille::getParameters();
        log::add('Abeille', 'debug', 'Envoi du message topic: ' . $topic . ' payload: ' . $payload . ' vers ' . $queueId);

        $queue = msg_get_queue($queueId);

        $msgAbeille = new MsgAbeille;

        $msgAbeille->message['topic'] = $topic;
        $msgAbeille->message['payload'] = $payload;

        if (msg_send($queue, $priority, $msgAbeille, true, false)) {
            log::add('Abeille', 'debug', 'Msg sent: ' . json_encode($msgAbeille) . ' on queue: ' . $queueId);
        } else {
            log::add('Abeille', 'debug', 'Could not send Msg: ' . json_encode($msgAbeille) . ' on queue: ' . $queueId);
        }
    }

    public static function createRuche($message = null)
    {

        $dest = $message->payload;
        $elogic = self::byLogicalId($dest . "/Ruche", 'Abeille');
        if (is_object($elogic)) {
            log::add('Abeille', 'debug', 'message: createRuche: objet: ' . $elogic->getLogicalId() . ' existe deja');
            return;
        }
        // Creation de la ruche
        log::add('Abeille', 'info', 'objet ruche : creation par model de ' . $dest . "/Ruche");

        /*
            $cmdId = end($topicArray);
            $key = count($topicArray) - 1;
            unset($topicArray[$key]);
            $addr = end($topicArray);
            // nodeid est le topic sans le dernier champ
            $nodeid = implode($topicArray, '/');
            */

        message::add("Abeille", "Création de l objet Ruche en cours, dans quelques secondes rafraichissez votre dashboard pour le voir.", '');
        $parameters_info = self::getParameters();
        $elogic = new Abeille();
        //id
        $elogic->setName("Ruche-" . $dest);
        $elogic->setLogicalId($dest . "/Ruche");
        if ($parameters_info['AbeilleParentId'] > 0) {
            $elogic->setObject_id($parameters_info['AbeilleParentId']);
        } else {
            $elogic->setObject_id(jeeObject::rootObject()->getId());
        }
        $elogic->setEqType_name('Abeille');
        $elogic->setConfiguration('topic', $dest . "/Ruche");
        $elogic->setConfiguration('type', 'topic');
        $elogic->setConfiguration('lastCommunicationTimeOut', '-1');
        $elogic->setIsVisible("1");
        $elogic->setConfiguration('icone', "Ruche");
        // eqReal_id
        $elogic->setIsEnable("1");
        // status
        $elogic->setTimeout(5); // timeout en minutes
        // $elogic->setCategory();
        // display
        // order
        // comment

        //log::add('Abeille', 'info', 'Saving device ' . $nodeid);
        //$elogic->save();
        $elogic->setStatus('lastCommunication', date('Y-m-d H:i:s'));
        $elogic->save();

        $rucheCommandList = AbeilleTools::getJSonConfigFiles('rucheCommand.json', 'Abeille');

        // Only needed for debug and dev so by default it's not done.
        if (0) {
            $i = 100;

            //Load all commandes from defined objects (except ruche), and create them hidden in Ruche to allow debug and research.
            $items = AbeilleTools::getDeviceNameFromJson('Abeille');

            foreach ($items as $item) {
                $AbeilleObjetDefinition = AbeilleTools::getJSonConfigFilebyDevices(AbeilleTools::getTrimmedValueForJsonFiles($item), 'Abeille');
                // Creation des commandes au niveau de la ruche pour tester la creations des objets (Boutons par defaut pas visibles).
                foreach ($AbeilleObjetDefinition as $objetId => $objetType) {
                    $rucheCommandList[$objetId] = array(
                        "name" => $objetId,
                        "order" => $i++,
                        "isVisible" => "0",
                        "isHistorized" => "0",
                        "Type" => "action",
                        "subType" => "other",
                        "configuration" => array("topic" => "CmdCreate/" . $objetId . "/0000-0005", "request" => $objetId, "visibilityCategory" => "additionalCommand", "visibiltyTemplate" => "0"),
                    );
                }
            }
            // print_r($rucheCommandList);
        }

        //Create ruche object and commands
        foreach ($rucheCommandList as $cmd => $cmdValueDefaut) {
            $nomObjet = "Ruche";
            log::add(
                'Abeille',
                'info',
                // 'Creation de la command: ' . $nodeid . '/' . $cmd . ' suivant model de l objet: ' . $nomObjet
                'Creation de la command: ' . $cmd . ' suivant model de l objet: ' . $nomObjet
            );
            $cmdlogic = new AbeilleCmd();
            // id
            $cmdlogic->setEqLogic_id($elogic->getId());
            $cmdlogic->setEqType('Abeille');
            $cmdlogic->setLogicalId($cmd);
            $cmdlogic->setOrder($cmdValueDefaut["order"]);
            $cmdlogic->setName($cmdValueDefaut["name"]);
            if ($cmdValueDefaut["Type"] == "action") {
                // $cmdlogic->setConfiguration('topic', 'Cmd' . $nodeid . '/' . $cmd);
                $cmdlogic->setConfiguration('topic', $cmd);
            } else {
                // $cmdlogic->setConfiguration('topic', $nodeid . '/' . $cmd);
                $cmdlogic->setConfiguration('topic', $cmd);
            }
            if ($cmdValueDefaut["Type"] == "action") {
                $cmdlogic->setConfiguration('retain', '0');
            }
            foreach ($cmdValueDefaut["configuration"] as $confKey => $confValue) {
                $cmdlogic->setConfiguration($confKey, $confValue);
            }
            // template
            $cmdlogic->setTemplate('dashboard', $cmdValueDefaut["template"]);
            $cmdlogic->setTemplate('mobile', $cmdValueDefaut["template"]);
            $cmdlogic->setIsHistorized($cmdValueDefaut["isHistorized"]);
            $cmdlogic->setType($cmdValueDefaut["Type"]);
            $cmdlogic->setSubType($cmdValueDefaut["subType"]);
            // unite
            $cmdlogic->setDisplay('invertBinary', '0');
            // La boucle est pour info et pour action
            foreach ($cmdValueDefaut["display"] as $confKey => $confValue) {
                // Pour certaine Action on doit remplacer le #addr# par la vrai valeur
                $cmdlogic->setDisplay($confKey, $confValue);
            }
            $cmdlogic->setIsVisible($cmdValueDefaut["isVisible"]);
            // value
            // html
            // alert

            $cmdlogic->save();
            $elogic->checkAndUpdateCmd($cmdId, $cmdValueDefaut["value"]);
        }
    }
}

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// La suite is Used for test
// en ligne de comande =>
// "php Abeille.class.php 1" to run the script to create any of the item listed in array L1057
// "php Abeille.class.php 2" to run the script to create a ruche object
// "php Abeille.class.php" to parse the file and verify syntax issues.
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

if (isset($argv[1])) {
    $debugKIWI = $argv[1];
} else {
    $debugKIWI = 0;
}

if ($debugKIWI != 0) {
    echo "Debut Abeille.class.php test mode\n";
    $message = new stdClass();

    switch ($debugKIWI) {

        // Creation des objets sur la base des modeles pour verifier la bonne creation dans Abeille
        case "1":
            $items = AbeilleTools::getDeviceNameFromJson('Abeille');
            $parameters_info = Abeille::getParameters();
            //problem icon creation
            foreach ($items as $item) {
                $name = str_replace(' ', '.', $item);
                $message->topic = substr($parameters_info['AbeilleTopic'], 0, -1) . "Abeille/$name/0000-0005";
                $message->payload = $item;
                Abeille::message($message);
                sleep(2);
            }
            break;

        // Demande la creation de la ruche
        case "2":
            $message->topic = "CmdRuche/Ruche/CreateRuche";
            $message->payload = "ttyUSB0";
            Abeille::message($message);
            break;

        // Verifie qu on recupere les IEEE pour les remplacer dans les commandes
        case "3":
            $ruche = new Abeille();
            $command = new AbeilleCmd();

            // Recupere IEEE de la Ruche/ZiGate
            $rucheId = $ruche->byLogicalId('Abeille/Ruche', 'Abeille')->getId();
            echo 'Id pour abeille Ruche: ' . $rucheId . "\n";

            $rucheIEEE = $commandIEEE->byEqLogicIdAndLogicalId($rucheId, 'IEEE-Addr')->execCmd();
            echo 'IEEE pour  Ruche: ' . $rucheIEEE . "\n";

            // $currentCommandId = $this->getId();
            // $currentObjectId = $this->getEqLogic_id();
            $currentObjectId = 284;
            echo 'Id pour current abeille: ' . $currentObjectId . "\n";

            $commandIEEE = $command->byEqLogicIdAndLogicalId($currentObjectId, 'IEEE-Addr')->execCmd();
            // print_r( $command->execCmd() );
            echo 'IEEE pour current abeille: ' . $commandIEEE . "\n";

            // $elogic->byLogicalId( 'Abeille/b528', 'Abeille' );
            // print_r( $objet->byLogicalId( 'Abeille/b528', 'Abeille' )->getId() );
            // echo "\n";
            // print_r( $command->byEqLogicIdAndLogicalId( $objetId, "IEEE-Addr" )->getLastValue() );

            $request = str_replace('#addrIEEE#', "'" . $commandIEEE . "'", $request);
            $request = str_replace('#ZiGateIEEE#', "'" . $rucheIEEE . "'", $request);

            break;

        // Testing Dependancy info
        case "4":
            $ruche = new Abeille();

            // echo "Testing Dependancy info\n";
            // var_dump( $ruche::getDependencyInfo() );

            echo "Testing deamon info\n";
            var_dump($ruche::deamon_info());
            break;

        // Cherche l objet qui a une IEEE specifique
        case "5":
            // Info ampoue T7
            $lookForIEEE = "000B57fffE490C2A";
            $checkShort = "2096";

            if (0) {
                $abeilles = Abeille::byType('Abeille');
                // var_dump( $abeilles );

                foreach ($abeilles as $num => $abeille) {
                    //var_dump( $abeille );
                    // var_dump( $abeille->getCmd('Info', 'IEEE-Addr' ) );
                    $cmdIEEE = $abeille->getCmd('Info', 'IEEE-Addr');
                    if ($cmdIEEE) {
                        // var_dump( $cmd );

                        if ($cmdIEEE->execCmd() == $lookFor) {
                            echo "Found it\n";
                            $cmdShort = $abeille->getCmd('Info', 'Short-Addr');
                            if ($cmdShort) {
                                echo $cmdShort->execCmd() . " ";
                                if ($cmdShort->execCmd() == $check) {
                                    echo "Success ";

                                    return 1;
                                } else {
                                    echo "Pas success du tout ";

                                    return 0;
                                }
                            }
                        }
                        echo $cmdIEEE->execCmd() . "\n-----\n";
                    }
                }

                return $cmd;
            } else {
                Abeille::fetchShortFromIEEE($lookForIEEE, $checkShort);
            }

            break;

        // Ask Model Identifier to all equipement without battery info, those equipement should be awake
        case "6":
            log::add('Abeille', 'debug', 'Ping routers to check Online status');
            $eqLogics = Abeille::byType('Abeille');
            foreach ($eqLogics as $eqLogic) {
                // echo "Battery: ".$collectBattery = $eqLogic->getStatus("battery")."\n";
                // echo "Battery: ".$collectBattery = $eqLogic->getConfiguration("battery_type")." - ";
                if (strlen($eqLogic->getConfiguration("battery_type")) == 0) {
                    $topicArray = explode("/", $eqLogic->getLogicalId());
                    $addr = $topicArray[1];
                    if (strlen($addr) == 4) {
                        echo "Short: " . $topicArray[1];
                        Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/" . $addr . "/Annonce", "Default");
                    }

                }
                echo "\n";
            }
            break;

        // Check Inclusion status
        case "7":
            echo "Check inclusion status\n";
            echo Abeille::checkInclusionStatus(Abeille::getParameters()['AbeilleSerialPort1']);

            break;

        // Check cleanup
        case "8":
            echo "Check cleanup\n";
            Abeille::deamon_start_cleanup();
            break;

        // Affichage Commandes
        case "9":
            // echo "Test Affichage\n";
            //  toggleAffichageNetwork
            //  toggleAffichageTime
            //  toggleAffichageAdditionalCommand

            // Abeille::CmdAffichage( "affichageNetwork", "toggle" );
            Abeille::CmdAffichage("affichageNetwork", "N");
            Abeille::CmdAffichage("affichageTime", "N");
            Abeille::CmdAffichage("affichageCmdAdd", "N");

            break;

        // Test jeeObject::all() function
        case "10":
            // $object = jeeObject::rootObject()->getId();
            $object = jeeObject::all();
            print_r($object);
            break;

        case "11":
            Abeille::syncconfAbeille(false);
            break;

        case "12":
            $cmds = Cmd::byLogicalId('IEEE-Addr');
            foreach ($cmds as $cmd) {
                if ($cmd->execCmd() == '00158D0001A66CA3') {
                    $abeille = $cmd->getEqLogic();
                    $abeille->setIsEnable(0);
                    $abeille->save();
                    $abeille->refresh();
                }
                echo "\n";
            }
            break;

        // Envoie un message a Abeille
        case "13":
            $message->topic = "Abeille1/92e9/0001-01-0021";
            $message->payload = "33";
            Abeille::message($message);
            break;

        case "14":
            Abeille::updateConfigAbeille();
            break;

        case "15":
            $abeilles = Abeille::byType('Abeille');
            foreach ($abeilles as $abeilleId => $abeille) {
                // var_dump($abeille->getCmd());
                // var_dump($abeille);
                echo $abeille->getId();
                return;
            }
            break;

        case "16":
            Abeille::cron15();
            break;

        case "17":
            // On verifie le cron
            var_dump(cron::byClassAndFunction('Abeille', 'deamon'));
            echo "Is Object: " . is_object(cron::byClassAndFunction('Abeille', 'deamon')) . "\n";
            echo "running: " . cron::byClassAndFunction('Abeille', 'deamon')->running() . "\n";
            break;

        case "18":
            Abeille::deamon();
            break;

        case "19":
            Abeille::deamon_start();
            break;

        case "20":
            Abeille::cronDaily();
            break;

        case "21":
            var_dump(Abeille::getParameters());
            break;

        case "22":
            echo "Debut case 22\n";
            $from = "Abeille1";
            $to = "Abeille3";
            $abeilles = Abeille::byType('Abeille');
            foreach ($abeilles as $abeilleId => $abeille) {
                // var_dump($abeille->getCmd());
                // var_dump($abeille);
                echo $abeille->getId() . "-> " . $abeille->getLogicalId() . "\n";
                if (preg_match("/^" . $from . "\//", $abeille->getLogicalId())) {
                    echo "to process: " . str_replace($from, $to, $abeille->getLogicalId()) . "\n";
                    $abeille->setLogicalId(str_replace($from, $to, $abeille->getLogicalId()));

                    echo "Name: " . $abeille->getName() . " - ";
                    $abeille->setName(str_replace($from, $to, $abeille->getName()));
                    echo "Name: " . $abeille->getName() . "\n";

                    echo "Conf: " . $abeille->getConfiguration('topic') . " - ";
                    $abeille->setConfiguration('topic', str_replace($from, $to, $abeille->getConfiguration('topic')));
                    echo "Conf: " . $abeille->getConfiguration('topic') . "\n";

                    $abeille->save();
                }
            }
            echo "\n";
            break;
        case "23":
            Abeille::tryToGetIEEE();
            break;
        case "24":
            // Debug:   log::level::Abeille    {"100":"1","200":"0","300":"0","400":"0","1000":"0","default":"0"} => 100 / debug
            // Default: log::level::Abeille    {"100":"0","200":"0","300":"0","400":"0","1000":"0","default":"1"} => 100 / debug
            // Info:    log::level::Abeille    {"100":"0","200":"1","300":"0","400":"0","1000":"0","default":"0"} => 200 / info
            echo log::getLogLevel('Abeille') . "\n";
            echo log::convertLogLevel(log::getLogLevel('Abeille')) . "\n";

            break;
        case "25":
            // Test message management
            message::add("Abeille", "Test5.", "Contactez KiwiHC16 sur le Forum.");
            break;
        case "26":
            // Test message management
            $addr = "2317";
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/" . $addr . "/managementNetworkUpdateRequest", "");
            break;
        case "27":
            // Test message management
            $addr = "df3b";
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/" . $addr . "/Mgmt_Rtg_req", "");
            break;
        case "28":
            $cmds = AbeilleCmd::searchConfiguration('Polling', 'Abeille');
            var_dump($cmds);
            break;
        case "29":
            $cmds = AbeilleCmd::searchConfigurationEqLogic(579, 'PollingOnCmdChange', 'action');
            var_dump($cmds);
            break;
        case "30":
            // Replace a cmd by an other
            jeedom::replaceTag(array('#17009#' => '#17251#'));
            break;
        case "31":
            //Remove an eq
            Abeille::byLogicalId('Abeille1/C359', 'Abeille', 0)->remove();
            break;
        case "32":
            Abeille::replaceGhost(588, 601);
            break;
        case "33":

            break;
        case "34":
            // Test Bind
            // 00158D0001ECBCBB <- zigate
            // 00124B0022292CF1 <- Touch
            // 00124B002242A288 <- Temp

            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/7f56/bindShort", "targetExtendedAddress=00124B002242A288&targetEndpoint=01&ClusterId=0001&reportToAddress=00158D0001ECBCBB");

            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/7f56/bindShort", "targetExtendedAddress=00124B002242A288&targetEndpoint=01&ClusterId=0402&reportToAddress=00158D0001ECBCBB");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/7f56/setReport", "targetEndpoint=01&ClusterId=0402&AttributeId=0000&AttributeType=20&MaxInterval=3500");

            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/7f56/bindShort", "targetExtendedAddress=00124B002242A288&targetEndpoint=01&ClusterId=0405&reportToAddress=00158D0001ECBCBB");
            Abeille::publishMosquitto(queueKeyAbeilleToCmd, priorityUserCmd, "CmdAbeille1/7f56/setReport", "targetEndpoint=01&ClusterId=0405&AttributeId=0000&AttributeType=20&MaxInterval=3500");
            break;
    } // switch

    echo "Fin Abeille.class.php test mode\n";
}
