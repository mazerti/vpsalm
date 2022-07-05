<?php

$PSALM_PATH = dirname($argv[0])."/vendor/bin/psalm";
$PSALM_PATH = preg_replace("#\\\\#", "/", $PSALM_PATH);

$sCwd = preg_replace("#\\\\#", "/", getcwd());
/** @psalm-suppress UnresolvableInclude */
include $sCwd . "/vpsalm-config.php";

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
        $this->sConfigFile = $sTargetFolder."psalm.xml";
        $this->sBaselineFile = $sTargetFolder."psalm-baseline.xml";
    }

    /** Create a temp Config file based on the global reference one. Save it in the $sConfigFile.
     * @param bool $noBaseline    Indicates if a baseline should be added to the config.
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
        assert(!$oConfig);
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
        global $argv;
        if (preg_match("#.*--version.*#", $sParams))
        {
            $sConf = "";
        }
        else if (preg_match("#.*--set-baseline.*#", $sParams))
        {
            $this->createConfig(true);
            $sConf = "-c $this->sConfigFile --find-unused-code ";
        }
        else
        {
            $this->createConfig();
            $this->createBaseline();
            $sConf = "-c $this->sConfigFile ";
        }
        $sRoot = dirname($argv[0]);
        $sCommand = "$PSALM_PATH --root=$sRoot $sConf$sParams 2> SpyErrors";
        return new Execution($sCommand);
    }
}

/**
 * Manages multiple versioned call to psalm, from getting the versions to fusion.
 */
final class VersionedAnalyser
{
    /** @var array|null $aVersions Stores the versions that should be checked */
    private $aVersions;
    /** @var array<string, Execution>|null $aAnalysis Contains the results of the analysis. The keys are the versions analysed.*/
    private $aAnalysis;
    /** @var string $sReturnFormat Describe the format in which we want the results to be returned. */
    private $sReturnFormat;
    /** @var array<string|int, string>|null $aResults Contains the errors logs to return.
     * The keys are the xml strings referencing an error,
     * the values are either a version or an empty string if the error exist in multiple evaluated versions */
    private $aResults;
    /** @var string|null $sResult The result of the analysis. */
    private $sResult;

    function __construct(string $sReturnFormat="xml")
    {
        $this->aVersions = null;
        $this->aAnalysis = null;
        $this->sReturnFormat = $sReturnFormat;
        $this->aResults = null;
        $this->sResult = null;
    }

    /** Checkout the versions to analyse and store them in $this->aVersions.
     * @return void
     */
    private function getVersions()
    {
        global $COMPOSER;
        $oComposer = fopen($COMPOSER, "r");
        $sPHPversions = json_decode(fread($oComposer, filesize($COMPOSER)), true)["require"]["php"];
        $aMatches = null;
        preg_match_all("#(\d\.\d\.\d)#", $sPHPversions, $aMatches);
        $this->aVersions = $aMatches[0];
    }

    /** Run Psalm for each version and store the results in $this->aAnalysis.
     * @return void
     */
    private function runPsalm()
    {
        global $argv;
        global $sCwd;
        $sTargetFile = $argv[count($argv) - 1];

        if ($sTargetFile == "--version")
        {
            $sTargetFolder = ".";
        }
        else
        {
            $sPattern = "#(\S+?Psalmtemp_folder(\d+)/|$sCwd/|\./|)(\S*)#";
            $aMatches = null;
            if (!preg_match($sPattern, $sTargetFile, $aMatches))
            {
                throw new Exception("Invalid path, must point toward either a project file
                or a copy with tree from project root with Psalmtemp_folderXXXX as root.");
            }
            $sTargetFolder = $aMatches[1];
        }

        assert(!is_null($this->aVersions), "No php version found");
        foreach ($this->aVersions as $sVersion)
        {
            $oInstance = new PsalmInstance($sVersion, $sTargetFolder);
            $iDashC = array_search("-c", $argv);
            if ($iDashC)
            {
                array_splice($argv, $iDashC, 2);
            }
            $sParams = implode(" ", array_slice($argv, 1));
            if (!preg_grep("#--output-format=\w+#", $argv))
            {
                $sParams .= " --output-format=checkstyle";
            }
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
        return array_search($sErrorType, $aIgnored) !== false;
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
        global $IGNORE_FILE;

        assert(!is_null($this->aAnalysis), "No analysis execution registered.");
        if ($argv[1] == "--version")
        {
            foreach ($this->aAnalysis as $sVersion=>$psalm)
            {
                assert($psalm->code()==0 or $psalm->code()==2, "Psalm encountered an internal issue dealing with version $sVersion.");
                if ($this->aResults == null)
                {
                    $this->aResults[0] = $psalm->stdout();
                }
                else if ($psalm->stdout() != $this->aResults[0])
                {
                    throw new Exception("Psalm version don't match, very weird !");
                }
            }
            assert(!is_null($this->aResults), "No psalm version get.");
            $this->sResult = $this->aResults[0];
        }
        else
        {
            /* First merge the different results, removing the duplicates. */
            foreach ($this->aAnalysis as $sVersion => $oExec)
            {
                assert($oExec->code()==0 or $oExec->code()==2, "Psalm encountered an internal issue dealing with version $sVersion. Error code : {$oExec->code()}");
                /** @var SimpleXMLElement $oWarnings */
                $oWarnings = simplexml_load_string($oExec->stdout());
                foreach ($oWarnings as $oErrorFile)
                {
                    $this->aResults[$oErrorFile->asXML()] = (isset($this->aResults[$oErrorFile->asXML()])) ? "" : $sVersion;
                }
            }

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
            $this->aResults = $this->aResults?:array();
            foreach ($this->aResults as $sError => $sVersion)
            {
                /** @var array $aIgnored */
                assert(is_string($sError), "Invalid key type (should be string). Weird.");
                if ($sVersion != "" or $bNoIgnore or !$this->isInErrors($aIgnored["type"], $sError))
                {
                    $sErrorLog = preg_replace("#(<file name=\"\S+\">\n\s*<error line=\"\d+\" column=\"\d+\" severity=\"\w+\" message)=\"(.+\"/>\n</file>)#", "$1=\"$sVersion:$2", $sError);
                    $this->sResult .= $sErrorLog."\n";
                }
            }
            if ($this->sResult == '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<checkstyle>'."\n")
            {
                $this->sResult .= '<file name="any/file">'."\n".' <error line="1" column="1" severity="error" message="Clean code, congratulations !"/>'."\n".'</file>'."\n";
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
        assert(!is_null($this->sResult), "There is nothing to return.");
        return $this->sResult;
    }

    /** Create baseline for all watched versions. */
    public function setBaselines():void
    {
        global $BASELINE_FOLDER;
        $this->getVersions();
        assert(!is_null($this->aVersions), "No php version found to analyse");
        foreach ($this->aVersions as $sVersion)
        {
            $oPsalmInstance = new PsalmInstance($sVersion, "");
            echo $oPsalmInstance->calLPsalm("--set-baseline=psalm-baseline.xml")->stdout();
            rename("psalm-baseline.xml", "$BASELINE_FOLDER/$sVersion.xml");
        }
    }
}
