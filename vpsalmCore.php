<?php

include "config.php";

/**
 * When instantiated execute the given command then stores its outputs
 */
class Execution {
    /** @var array|null */
    private $aReturns = null;
    /** @var int|null */
    private $iCode = null;
    /** @var string|null */
    private $sReturn;
    function __construct(string $cmd){
        $debug = getcwd();
        exec($cmd, $this->aReturns, $this->iCode);
        /** @var array<array-key, string> aReturns */
        $this->aReturns = $this->aReturns ?: array();
        $this->sReturn = implode("\n", $this->aReturns);
    }
    public function stdout(): string{
        /** @var string sReturn */
        return $this->sReturn;
    }
    public function code(): int{
        /** @var int iCode */
        return $this->iCode;
    }
}

/**
 * Generate the $SPY_REPORT file to debug the phpstorm integration.
 */
class Spy
{
    /** @var string $sErrors_File A file that can be used to store Errors. */
    public $sErrors_File;
    /** @var float $fTimeZero Time of the instantiation. */
    private $fTimeZero;
    /** @var array $aReport The reports from the last refresh. */
    private $aReport;
    /** @var string $id Identify the current report in $this->aReport. */
    private $id;

    /**
     * @param array $arg        $argv typically.
     * @param bool $bRefresh    $should the output be refreshed ?
     */
    function __construct(array $arg, bool $bRefresh = false)
    {
        global $SPY_REPORT;
        $this->sErrors_File = sys_get_temp_dir()."/SpyErrors";
        $this->fTimeZero = microtime(true);
        $this->aReport = ($bRefresh) ? array() : json_decode(file_get_contents($SPY_REPORT), true);
        $this->id = date("G:i") ."$:".implode(" ", $arg);
        while (key_exists($this->id, $this->aReport))
        {
            $this->id .= "`";
        }
        $this->aReport[$this->id] = array(
            "Sub Calls"=>array(),
            "Return"=>"",
            "Debug"=>"",
        );
    }
    
    public function watchCall(string $call, bool $watch_errors)
    {
        $this->aReport[$this->id]["Sub Calls"][] = array(
            "Command" => $call,
            "Duration" => microtime() - $this->fTimeZero
        );
        if ($watch_errors)
        {
            array_key_last($this->aReport[$this->id]["Sub Calls"])["Errors"] = file_get_contents($this->sErrors_File);
        }
    }

    public function watchDebug(string $content)
    {
        $this->aReport[$this->id]["Debug"] .= "$content\n";
    }

    public function __destruct()
    {
        global $SPY_REPORT;
        file_put_contents($SPY_REPORT, json_encode($this->aReport));
    }
}

/**
 * Versioned access to Psalm adapting baseline to temp file if needed.
 */
class PsalmInstance
{
    /** @var string $sVersion The version this instance is going to analyse. */
    public $sVersion;
    /** @var string $sConfigFile The folder with the config for this instance.*/
    public $sConfigFile;
    /** @var string $sBaselineFile The folder with the baseline for this instance. */
    public $sBaselineFile;

    function __construct(string $sVersion, string $sTargetFolder)
    {
        $this->sVersion = $sVersion;
        $this->sConfigFile = $sTargetFolder."/psalm.xml";
        $this->sBaselineFile = $sTargetFolder."/psalm-baseline.xml";
    }

    /** Create a temp Config file based on the global reference one. Save it in the $sConfigFile.
     * @var bool $noBaseline    Indicates if a baseline should be added to the config.
     * @return void
     */
    private function createConfig(bool $noBaseline=false): void
    {
        global $CONFIG_FILE;
        $oConfig = simplexml_load_file($CONFIG_FILE);
        $oConfig->attributes()["phpVersion"] = $this->sVersion;
        if ($noBaseline)
        {
            unset($oConfig->attributes()["errorBaseline"]);
        }
        else
        {
            if (isset($oConfig->attributes()["errorBaseline"]))
            {
                $oConfig->attributes()["errorBaseline"] = $this->sBaselineFile;
            }
            else
            {
                $oConfig->addAttribute("errorBaseline", $this->sBaselineFile);
            }
        }
        if (realpath($this->sConfigFile) != realpath($CONFIG_FILE))
        {
            unset($oConfig->projectFiles);
        }
        file_put_contents($this->sConfigFile, $oConfig->asXML());
    }

    /** Create a temp baseline file based on the global reference one matching with the version. Save it in the $sBaselineFile
     *
     * @return void
     */
    private function createBaseline(): void
    {
        global $BASELINE_FOLDER;
        copy("$BASELINE_FOLDER/$this->sVersion.xml", $this->sBaselineFile);
    }

    /** Call Psalm with the specific version parameters.
     *
     * @param string $sParams The parameters to give to Psalm.
     * @return Execution
     */
    public function calLPsalm(string $sParams): Execution
    {
        global $PSALM_PATH;
        if (preg_match("#.*--version.*#", $sParams))
        {
            $sConf = "";
        }
        else if (preg_match("#.*--set-baseline.*#", $sParams))
        {
            $this->createConfig(true);
            $sConf = "-c $this->sConfigFile ";
        }
        else
        {
            $this->createConfig();
            $this->createBaseline();
            $sConf = "-c $this->sConfigFile ";
        }
        $sCommand = "$PSALM_PATH $sConf$sParams 2> SpyErrors";
        return new Execution($sCommand);
    }
}

/**
 * Manages multiple versioned call to psalm, from getting the versions to fusion.
 */
final class VersionedAnalyser
{
    /** @var array $aVersions Stores the versions that should be checked */
    private $aVersions;
    /** @var array<string, Execution> $aAnalysis Contains the results of the analysis. The keys are the versions analysed.*/
    private $aAnalysis;
    /** @var string $sReturnFormat Describe the format in which we want the results to be returned. */
    private $sReturnFormat;
    /** @var array<string, string> $aResults Contains the errors logs to return.
     * The keys are the xml strings referencing an error,
     * the values are either a version or an empty string if the error exist in multiple evaluated versions */
    private $aResults;
    /** @var string $sResult The result of the analysis. */
    private $sResult;

    /** Checkout the versions to analyse and store them in $this->aVersions.
     * @return void
     */
    private function getVersions()
    {
        global $COMPOSER;
        $oComposer = fopen($COMPOSER, "r");
        $sPHPversions = json_decode(fread($oComposer, filesize($COMPOSER)), true)["require"]["php"];
        preg_match("#>=(\d.\d.\d) <(\d.\d.\d)#", $sPHPversions, $this->aVersions);
        array_splice($this->aVersions, 0, 1);
    }

    /** Run Psalm for each version and store the results in $this->aAnalysis.
     * @return void
     */
    private function runPsalm()
    {
        global $argv;
        $sTargetFile = $argv[count($argv) - 1];
        foreach ($this->aVersions as $sVersion)
        {
            $cwd = preg_replace("#\\\\#", "/", getcwd());
            $sPattern = "#(\S+?Psalmtemp_folder(\d+)|$cwd|\.)/(\S*)#";
            $aMatches = null;
            if (!preg_match($sPattern, $sTargetFile, $aMatches))
            {
                throw new Exception("Invalid path, must point toward either a project file
                or a copy with tree from project root with Psalmtemp_folderXXXX as root.");
            }
            $sTargetFolder = $aMatches[1];

//            $sPattern = "#(Psalmtemp_folder(\d+)|C:)/(\S*)#";
//            $sTargetFolder = preg_replace($sPattern, "Psalmtemp_folder$1/", $sTargetFile);
            
            $oInstance = new PsalmInstance($sVersion, $sTargetFolder);
            $iDashC = array_search("-c", $argv);
            if ($iDashC)
            {
                array_splice($argv, $iDashC, 2);
            }
            $sParams = implode(" ", array_slice($argv, 1));
            $this->aAnalysis[$sVersion] = $oInstance->calLPsalm($sParams);
        }
    }

    /** Check if the given error is in the $IGNORE_fILE
     * @param array $aIgnored   An array of Errors types that should be ignored.
     * @param string $sError    The error we want to check.
     * @return bool
     */
    private function isInErrors(array $aIgnored, string $sError): bool
    {
        $sPattern = "#(.|\n)*<file name=\"\S+\">\n\s*<error line=\"\d+\" column=\"\d+\" severity=\"\w+\" message=\"(\w+):.*\"/>\n\s*</file>(.|\n)*#";
        $sErrorType = preg_replace($sPattern, "$2", $sError);
        return array_search($sErrorType, $aIgnored) == false;
    }


    /* Compare Psalm's output and return a summarized version. */
    /** Sort the errors from the different Psalm call and create a new summary with
     *  - each exception only once
     *  - an indicator if the error is only for a specific version of php
     *  - some exception excluded if they are int all versions.
     * @return void         The result is stored in $this->aResults as an array associating xml strings of errors with versions tags. Then create $this->sResult.
     * @throws Exception    If at least one version caused Psalm to return an error.
     */
    private function sortErrors()
    {
        global $argv;
        if ($argv[1] == "--version")
        {
            foreach ($this->aAnalysis as $sVersion=>$psalm)
            {
                if ($psalm->code() != 0)
                {
                    throw new Exception("Call to version $sVersion went wrong, error code : {$psalm->code()}");
                }
                if ($this->aResults == null)
                {
                    $this->aResults[0] = $psalm->stdout();
                }
                else if ($psalm->stdout() != $this->aResults[0])
                {
                    throw new Exception("Psalm version don't match, very weird !");
                }
            }
            $this->sResult = $this->aResults[0];
        }
        else
        {
            /* First merge the different results, removing the duplicates. */
            foreach ($this->aAnalysis as $sVersion => $oExec)
            {
                if ($oExec->code() != 0)
                {
                    //throw new Exception("Call to version $sVersion went wrong, error code : {$oExec->code()}");
                }
                /** @var SimpleXMLElement $oWarnings */
                $oWarnings = simplexml_load_string($oExec->stdout());
                foreach ($oWarnings as $oErrorFile)
                {
                    $this->aResults[$oErrorFile->asXML()] = (isset($this->aResults[$oErrorFile->asXML()])) ? "" : $sVersion;
                }
            }

            global $IGNORE_FILE;
            /* Parse $this->aResults and compose $this->sReturn, filtering unwanted errors. */
            $this->sResult = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<checkstyle>'."\n";
            if (is_file($IGNORE_FILE))
            {
                $bNoIgnore = false;
                $oXMLIgnored = simplexml_load_file($IGNORE_FILE);
                $oJsonIgnored = json_encode($oXMLIgnored);
                $aIgnored = json_decode($oJsonIgnored, true);
            }
            else
            {
                $bNoIgnore = true;
            }
            foreach ($this->aResults as $sError => $sVersion)
            {
                /** @var array $aIgnored */
                if ($sVersion != "" or $bNoIgnore or !$this->isInErrors($aIgnored, $sError))
                {
                    $this->sResult .= preg_replace("#(\<file name=\"\S+\"\>\n\<error line=\"\d+\" column=\"\d\" severity=\"\w+\" messge=\")(\S+\"/\>\n\</file\>)#",
                            "$1$sVersion$2", $sError)."\n";
                }
            }
            $this->sResult .= '</checkstyle>'."\n";
        }
    }

    /** Run a multi-version psalm analysis and return the (xml formatted) exhaustive summary.
     * @return string
     * @throws Exception
     */
    public function run(): string
    {
        $this->getVersions();
        $this->runPsalm();
        $this->sortErrors();
        return $this->sResult;
    }

    /** Create baseline for all watched versions. */
    public function setBaselines()
    {
        global $BASELINE_FOLDER;
        $this->getVersions();
        foreach ($this->aVersions as $sVersion)
        {
            $oPsalmInstance = new PsalmInstance($sVersion, ".");
            echo $oPsalmInstance->calLPsalm("--set-baseline=psalm-baseline.xml")->stdout();
            $debug = getcwd();
            rename("psalm-baseline.xml", "$BASELINE_FOLDER/$sVersion.xml");
        }
    }
}
