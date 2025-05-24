<?php

use packager\{
    cli\Console, Event, JavaExec, Packager, Vendor, Colors
};
use compress\ZipArchive;
use php\io\File;
use php\lib\str;
use php\util\Configuration;
use php\lib\fs;
use compress\ZipArchiveEntry;
use php\io\FileStream;
use php\lib\arr;
use php\io\MiscStream;
use compress\ZipArchiveInput;


/**
 * Class DevelNextBundle
 * @jppm-task-prefix bundle
 *
 * @jppm-task build as build
 * @jppm-task init as init
 */
class DevelNextBundle
{
    /**
     * @jppm-need-package
     * @jppm-description Build project and create dnbundle.
     * @param Event $event
     */
    public function build(Event $e){
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
        
        fs::scan("src-bundle", function(File $file) use ($bundle, $config){
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
            if(!$extFile) {
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
     * @jppm-description init bundle path structure.
     * @param Event $event
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

    private function makeClassFile ($classFile, $namespace, $className)
    {
        fs::makeFile($classFile);

        $classTemplate = <<<'PHP_CLASS'
<?php

namespace %namespace%;

use ide\bundle\AbstractJarBundle;

class %className% extends AbstractJarBundle
{
    // Autogenerated file
}
PHP_CLASS;

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