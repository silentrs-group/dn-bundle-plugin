# dn-bundle-plugin
Plugin for creating bundles for DevelNext using jppm
## Usage
Edit you package.php.yml like this:
```yaml
devDeps:
  dn-bundle-plugin: '*'
...

develnext-bundle:
  version: 1.0.0
  name: simple-bundle
  author: broelik
  icon: "develnext/bundle/simplebundle/icon32.png"
  description: "Simple Bundle"
  group: "other"
  class: "develnext\\bundle\\simplebundle\\SimpleBundle"
```

```yaml
develnext-bundle-config:
  # исключить директорию \ файл из добавления в пакет
  exclude: ["src-bundle\\META-INF"] 

  # создать дополнительные jar файлы из указанных директорий
  extraJar: 
    - name: "sdk.jar"
      files: ["src-bundle\\META-INF"]

    - name: "fromRootDir.jar"
      files: ["JPHP-INF"]
```
After, create **src-bundle** folder in you project directory. This folder contains source files for bundle and can be used to interact with IDE.

Then use this command for create bundle:
```
jppm bundle:build
```
You can find dnbundle file at **PROJECT_DIRECTORY**/bundle/**PROJECT_NAME**-**PROJECT_VERSION**.dnbundle
