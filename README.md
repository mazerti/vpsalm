# VPsalm

A sub-script for psalm managing the following features :

 - Psalm baseline in PHPStorm : PHPStorm's Psalm plugin uses temporary files which makes the baseline feature inemployable. This script does the necessary manipulations to correct this.
 - Managing phpVersion range : According to the composer.json, VPsalm will call psalm on limit cases and reserve a special treatment for the warnings raised on a single version.
 - Dynamic Baseline management : VPsalm create a baseline for each php file analyzed for the first time (while analyzed alone) so that you won't be bothered by legacy code.

## Installation

1. Load the current repo where you can find it easily
2. Install psalm to this repo using `composer require vimeo/psalm`
3. Write a psalm config file in your project. You can either call `psalm --init` or write it yourself using [psalm documentation](https://psalm.dev/docs/running_psalm/configuration/)
4. copy the vpsalm-config file from this repo to your project and edit the files path so they match those of your project.
5. In PHPStorm, link the Psalm plugin to vpsalm : in [File | Settings | PHP | Quality Tools](jetbrains://PhpStorm/settings?name=PHP--Quality+Tools) click the `...` next to psalm configuration. There, point psalm path toward **vpsalm.bat**.
6. Change your inspection profile to show psalm inspections.

## Usage

You may use vpsalm in one of the following way : 

- As PHPStorm inspection : Once the last two installation steps are done it should work automaticaly. When you will open a php file for the first time using vpsalm a baseline file will be generated for this file and phpstorm will only report the error "clean code, congratulations !" (attesting vpsalm run properly). If you write dirty code then vpsalm will warn you about the new errors. To reset a baseline just delete the according baseline file and open the php file again with phpstorm.
- Using CLI : you can call vpsalm using CLI just as you would do with psalm. You just have to respect the following constraints :
  - Call it from the root of your project
  - If you want to analyze a single file put it at the end of your CLI call
  - If you want to analyze several files register them in your psalm.xml config file (it may have a different name) and make sure the last CLI argument is not a php file.

## Git usage

To make sure not to add any warning at with your future commits you may generate new baselines after each commit, phpstorm's git plugin allow you to run tool after commit, you can just use that to delete the baseline folder. To keep a coherent 
