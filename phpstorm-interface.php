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
    /** @var array $aLogs An array containing the logs of the program. */
    private $aLog;

    function __construct(bool $bRefresh = false)
    {
        global $SPY_REPORT;
        $this->sErrors_File = sys_get_temp_dir()."/SpyErrors";
        $this->fTimeZero = microtime(true);
        $this->aLog = array(
            "Code"=>"",
            "Errors"=>"",
            "Debug"=>"",
        );
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

    function __construct(string $sVersion, string $sTempFolder)
    {
        $this->sVersion = $sVersion;
        $this->sConfigFile = $sTempFolder."/psalm.xml";
        $this->sBaselineFile = $sTempFolder."/psalm-baseline.xml";
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
            $oConfig->attributes()["errorBaseline"] = $this->sBaselineFile;
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
        if (!preg_match("#.* --set-baseline.*#", $sParams))
        {
            $this->createConfig();
            $this->createBaseline();
        }
        else
        {
            $this->createConfig(true);
        }
        $sCommand = "$PSALM_PATH -c $this->sConfigFile $sParams";
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
        preg_match("#>=(\d.\d.\d) <=(\d.\d.\d)#", json_decode(fread($oComposer, filesize($COMPOSER)), true)["require"]["php"], $this->aVersions);
        array_splice($this->aVersions, 0, 1);
    }

    /** Run Psalm for each version and store the results in $this->aAnalysis.
     * @return void
     */
    private function runPsalm()
    {
        global $argv;
        $sTarget = $argv[count($argv) - 1];
        foreach ($this->aVersions as $sVersion)
        {
            $sTempFolder = preg_replace("#Psalmtemp_folder(\d+)/(\S*)#", "Psalmtemp_folder$1/", $sTarget);
            $oInstance = new PsalmInstance($sVersion, $sTempFolder);
            $iDashC = array_search("-c", $argv);
            if ($iDashC)
            {
                array_splice($argv, $iDashC, 2);
            }
            $sParams = implode(" ", array_slice($argv, 1));
            $this->aAnalysis[] = $oInstance->calLPsalm($sParams);
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
     * @return void         The result is stored in $this->aResults as an array of versions tags indexed with xml strings of elements. Then create $this->sResult.
     * @throws Exception    If at least one version caused Psalm to return an error.
     */
    private function sortErrors()
    {
        /* First merge the different results, removing the duplicates. */
        foreach ($this->aAnalysis as $sVersion => $oExec)
        {
            if ($oExec->code() != 0)
            {
                /* An Error has occurred, crash the program detailing the situation to the maximum. */
                throw new Exception("Call to version $sVersion went wrong, error code : {$oExec->code()}");
            }
            /** @var SimpleXMLElement $oWarnings */
            $oWarnings = simplexml_load_string($oExec->stdout());
            foreach ($oWarnings as $oErrorFile)
            {
                $this->aResults[$oErrorFile->asXML()] = (array_key_exists($oErrorFile->asXML(), $this->aResults)) ? "" : $sVersion;
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
                $oError = simplexml_load_string($sError);
                $oError->error->attributes()["message"] = $sVersion . $oError->error->attributes()["message"];
                $this->sResult .= $oError->asXML()."\n";
            }
        }
        $this->sResult .= '</checkstyle>'."\n";
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
            rename("psalm-baseline.xml", "$BASELINE_FOLDER/$sVersion.xml");
        }
    }
}
