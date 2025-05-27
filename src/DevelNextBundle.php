<?php

use packager\{
    cli\Console, Event, JavaExec, Packager, Vendor, Colors
};

use php\lib\{str, fs, arr};
use php\util\Configuration;
use compress\{ZipArchive, ZipArchiveEntry, ZipArchiveInput};
use php\io\{File, FileStream, MiscStream};



/**
 * Class DevelNextBundle
 * @jppm-task-prefix bundle
 *
 * @jppm-task build as build
 * @jppm-task init as init
 * @jppm-task initScriptComponent as initScriptComponent
 */
class DevelNextBundle
{
    /**
     * @jppm-need-package
     * @jppm-description Build project and create dnbundle.
     * @param Event $e
     */
    public function build(Event $e) {
        Tasks::run('build', []);

        $packageName = $e->package()->getName();

        $buildFileName = "{$packageName}-{$e->package()->getVersion('last')}";

        Tasks::createDir('bundle');
        Tasks::cleanDir('bundle');



        // ------------------------------------------------------
        // *                build config section                *
        // ------------------------------------------------------

        $resourceConf = new Configuration();
        $resourceConf->put($e->package()->getAny('develnext-bundle'));
        $resourceConf->save("bundle/.resource");



        $config = $e->package()->getAny('develnext-bundle-config');

        // ------------------------------------------------------
        // *     make main jar file from src-bundle directory   *
        // ------------------------------------------------------

        Tasks::createFile("bundle/dn-{$packageName}-bundle.jar");

        $bundle = new ZipArchive("bundle/dn-{$packageName}-bundle.jar");
        $bundle->open();
        
        fs::scan("src-bundle", function(File $file) use ($bundle, $config) {
            if ($file->isFile()) {

                // exclude files
                if (isset($config["exclude"])) {
                    foreach ($config["exclude"] as $exclude) {
                        if (str::startsWith(fs::normalize($file), $exclude)) {
                            Console::log("Skip file: " . ((string) $file));
                            return;
                        }
                    }
                }

                $bundle->addFile($file, fs::relativize($file, "src-bundle"));
            }
        });


        $prefix = str::replace($resourceConf->get("class"), "\\", ".");

        fs::scan("src", function(File $file) use ($bundle, $prefix) {
            if ($file->isFile()) {
                $bundle->addFile($file, "vendor/{$prefix}/" . fs::relativize($file, "src"));
            }
        });

        $bundle->close();



        // ------------------------------------------------------
        // *        TODO: here need make extra jar files        *
        // ------------------------------------------------------

        if (isset($config["extraJar"])) {
            foreach ($config["extraJar"] as $jar) {
                $bundle = new ZipArchive("jars/{$jar['name']}");
                $bundle->open();

                foreach ($jar["files"] as $source) {
                    fs::scan($source, function(File $file) use ($bundle, $source) {
                        if ($file->isFile()) {
                            $bundle->addFile($file, fs::relativize($file, fs::parent($source)));
                        }
                    });

                    $bundle->close();
                }
            }
        }



        // ------------------------------------------------------
        // *                 make dnbundle file                 *
        // ------------------------------------------------------

        Tasks::createFile("bundle/{$buildFileName}.dnbundle");

        $out = new ZipArchive("bundle/{$buildFileName}.dnbundle");
        $out->open();
        $out->addFile("bundle/dn-{$packageName}-bundle.jar", "bundle/dn-{$packageName}-bundle.jar");
        $out->addFile("bundle/.resource", ".resource");

        /** @var File $extFile */
        $extFile = null;



        // ------------------------------------------------------
        // *                  add all jar files                 *
        // ------------------------------------------------------
        fs::scan('jars', function (File $file) use ($out, &$extFile) {
            if (!$extFile) {
                $jar = new ZipArchive($file);

                $entry = $jar->read('META-INF/services/php.runtime.ext.support.Extension');

                if ($entry) {
                    $extFile = $file;
                    return;
                }

            }

            $out->addFile($file, "bundle/{$file->getName()}");
        });

        if ($extFile) {
            Tasks::createDir("bundle/ext");

            $input = new ZipArchiveInput($extFile);

            while ($entry = $input->nextEntry()) {
                if ($input->canReadEntryData($entry) && !$entry->isDirectory()) {
                    $name = "bundle/ext/{$entry->name}";

                    fs::ensureParent($name);
                    fs::copy($input->stream(), $name);
                }
            }

            Tasks::copy("sdk", "bundle/ext/JPHP-INF/sdk");

            $jar = new ZipArchive("bundle/{$extFile->getName()}");
            $jar->open();

            fs::scan("bundle/ext", function(File $file) use ($jar) {
                if ($file->isFile()) {
                    $jar->addFile($file, fs::relativize($file, "bundle/ext"));
                }
            });
            $jar->close();
            $out->addFile("bundle/{$extFile->getName()}", "bundle/{$extFile->getName()}");
        }

        $out->close();

        Tasks::deleteFile("bundle/.resource", true);

        if ($extFile) {
            Tasks::deleteFile("bundle/{$extFile->getName()}", true);
        }

        Tasks::deleteFile("bundle/dn-{$packageName}-bundle.jar", true);
        Tasks::cleanDir("bundle/ext", [], true);
        Tasks::deleteFile("bundle/ext", true);

        Console::log("You can find bundle in " . fs::abs("bundle/{$buildFileName}.dnbundle"));
    }

    /**
     * @jppm-need-package
     * @jppm-description init bundle path and files structure.
     * @param Event $e
     */
    public function init (Event $e)
    {
        $config = $e->package()->getAny('develnext-bundle');

        if (!isset($config)) {
            Console::log(Colors::withColor("Missing or invalid plugin configuration", "red"));
            return;
        }

        $rootDir   = 'src-bundle';

        $className = fs::name($config["class"]);
        $namespace = str::lower(fs::parent($config["class"]));

        $iconFile  = sprintf('%s/.data/img/%s/%s', $rootDir, str::lower(fs::parent($config["icon"])), fs::name($config["icon"]));
        $classFile = sprintf('%s/%s/%s.php', $rootDir, $namespace, $className);

        fs::ensureParent(fs::parent($iconFile));
        fs::ensureParent(fs::parent($classFile));

        $this->makeClassFile($classFile, $namespace, $className);
        $this->makeBlankIcon($iconFile);
    }

    /**
     * @jppm-need-package
     * @jppm-description init bundle scriptComponent path and files structure.
     * @param Event $e
     */
    public function initScriptComponent (Event $e)
    {
        $config = $e->package()->getAny('develnext-bundle');

        $name = fs::name($config["class"]);
        $nameWithoutBundle = str::sub($name, 0, str::length($name) - 6);

        $r = \templates\ScriptComponentTemplate::getData(
            fs::parent($config["class"]),
            $name,
            fs::name(fs::parent($config["class"])),
            $nameWithoutBundle,
            "bundle\\" . fs::name(fs::parent($config["class"])),
            $config["class"]
        );

        if (is_array($r)) {
            Tasks::run('bundle:init');
        }

        foreach ($r as $file) {
            $path = 'src-bundle/';

            fs::ensureParent($path  . fs::parent($file["filepath"]));
            fs::makeFile($path  . $file["filepath"]);

            FileStream::putContents($path  . $file["filepath"], $file["filedata"]);
        }
    }

    private function makeClassFile ($classFile, $namespace, $className)
    {
        fs::makeFile($classFile);

        $classTemplate = '<?php

namespace %namespace%;

use ide\bundle\AbstractJarBundle;

class %className% extends AbstractJarBundle
{
    // Autogenerated file
}';

        FileStream::putContents($classFile, str_replace(
            ["%namespace%", "%className%"],
            [$namespace, $className],
            $classTemplate
        ));
    }

    private function makeBlankIcon ($iconFile)
    {
        fs::makeFile($iconFile);

        FileStream::putContents($iconFile, base64_decode("iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAYSURBVDhPY/j//z8D1nDq1CkGNAYQYwEAW+wIdQp0Xj0AAAAASUVORK5CYII="));
    }
}