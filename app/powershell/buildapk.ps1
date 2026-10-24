function build($env, $bundleID, $appName, $templateID) {
    Write-Output('*****   Build:' + $appName + '   *****')
    $isRelease = If ($env -eq 0) { "false" } Else { "true" }

    # change icon & splash
    $iconPath = '.\powershell\icon\{0}.png' -f $appName
    $splashPath = '.\powershell\splash\{0}.png' -f $appName
    Copy-Item -Path $iconPath -Destination '.\dev_assets\icon.png' -Force
    Copy-Item -Path $splashPath -Destination '.\android\app\src\main\res\drawable\splash.png' -Force
    flutter pub run flutter_launcher_icons:main

    # file
    $appSettingFile = '.\lib\utils\app_setting.dart'
    $bundleIDFile = '.\android\app\build.gradle'
    $AndroidManifestFile = '.\android\app\src\main\AndroidManifest.xml'
    $pubspecFile = '.\pubspec.yaml'
    $mainFile = '.\lib\main.dart'

    #Template asset
    $assetsPath = $templateID.foreach{ "`n    - assets/images/$PSItem/" }
    (Get-Content -path $pubspecFile -Raw) -replace ("(?s)#template start.*#template end", "#template start$assetsPath`n#template end")  | Set-Content $pubspecFile 
    flutter pub get

    # line
    $envLine = Get-Content $appSettingFile | Select-String 'int _envId =' | Select-Object -ExpandProperty Line
    $themeLine = Get-Content $appSettingFile | Select-String 'L1Theme currentTheme =' | Select-Object -ExpandProperty Line
    $releaseLine = Get-Content $appSettingFile | Select-String 'bool _isRelease =' | Select-Object -ExpandProperty Line
    $bundleIDLine = Get-Content $bundleIDFile | Select-String 'applicationId ' | Select-Object -ExpandProperty Line
    $appNameLine = Get-Content $AndroidManifestFile | Select-String 'android:label=' | Select-Object -ExpandProperty Line
    $webViewProviderLine = Get-Content $AndroidManifestFile | Select-String '.flutter_inappwebview.fileprovider"' | Select-Object -ExpandProperty Line
    $titleLine = Get-Content $mainFile | Select-String 'title: ' | Select-Object -ExpandProperty Line

    # change string
    (Get-Content $appSettingFile -encoding utf8).replace($envLine, 'int _envId = {0};' -f $env)        | Set-Content $appSettingFile -encoding utf8
    (Get-Content $appSettingFile -encoding utf8).replace($themeLine, 'L1Theme currentTheme = l1{0}Theme;' -f $templateID[0].ToUpper())        | Set-Content $appSettingFile -encoding utf8
    (Get-Content $appSettingFile -encoding utf8).replace($releaseLine, 'bool _isRelease = {0};' -f $isRelease)      | Set-Content $appSettingFile -encoding utf8
    (Get-Content $bundleIDFile).replace($bundleIDLine, '        applicationId "{0}"' -f $bundleID) | Set-Content $bundleIDFile
    (Get-Content $AndroidManifestFile).replace($webViewProviderLine, '            android:authorities="{0}.flutter_inappwebview.fileprovider"' -f $bundleID) | Set-Content $AndroidManifestFile
    (Get-Content $AndroidManifestFile).replace($appNameLine, '        android:label="{0}"' -f $appName) | Set-Content $AndroidManifestFile
    (Get-Content $mainFile).replace($titleLine, "      title: '{0}'," -f $appName) | Set-Content $mainFile -encoding utf8

    # build
    flutter build apk

    # export
    $versionLine = Get-Content $pubspecFile | Select-String 'version: ' | Select-Object -ExpandProperty Line
    $version = $versionLine -replace "[^0-9.]" , ''
    $versionPath = '.\powershell\build\{0}' -f $version
    if (!(Test-Path -path $versionPath)) { New-Item $versionPath -Type Directory }
    $fileName = '{0}-v{1}.apk' -f $appName.replace(' ', '-'), $version;
    $apkDestination = '{0}\{1}' -f $versionPath, $fileName
    Copy-Item -Path '.\build\app\outputs\flutter-apk\app-release.apk' -Destination $apkDestination -Recurse -Force
    Write-Output('Destination: ' + $apkDestination)
}
# build '0'         'com.vworlddev.android01'           'Dev-V-World'                   @("c711","c712","c713");
# build '2'         'com.vworld.android01'              'VWorld MY'                     @("c711");
# build '9'         'com.ewallet.android01'             'EWallet'                       @("c711");
# build '10'        'com.hmlogo.android01'              'HMLOGO'                        @("c711");
# build '12'        'com.redbull.android01'             'RedBull'                       @("c711");
# build '13'        'com.deluxeclub.android01'          'DeluxeClub'                    @("c711");
# build '15'        'com.vworldsg.android01'            'VWorld SG'                     @("c711");
# build '16'        'com.vclass.android01'              'vclass'                        @("c711");
# build '17'        'com.vworldvn.android01'            'VWorld VN'                     @("c711");





