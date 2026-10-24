# 說明
幫助類型的私有套件, 概念上來說只要是跨服務會使用到的一些業務邏輯, 可以選擇放到這裡來共用  

# 套件引用
#### 添加套件倉庫
composer.json
```json
{
    "repositories": {
		"support-package": {
			"type": "vcs",
			"url": "ssh://user@example.invalid:1022/target/components/support.git"
		}
	}
}
```
#### 安裝新的私有套件
```bash
composer require target/support:dev-master
```
#### 更新私有套件
```bash
git checkout -b composer
composer update target/support
git add .
git commit -m "composer update target/support"
git checkout develop #切換到你要更新的分支
git merge composer #把剛才做過的套件更新合併到你要更新的分支
```
`切記 composer update 更新務必要在 composer 分支下進行`  
`切記 composer update 更新務必要在 composer 分支下進行`   
`切記 composer update 更新務必要在 composer 分支下進行`    

# 套件開發
#### 套件安裝位置
```
vendor/target/support
```
#### 修改代碼, 調試完要提交上去
```bash
git status
git add blahblahblah...
git commit -m "提交內容"
git push
```

#### 擴充 support 規範
1. 修改時需遵循 phpcs psr2 及 phpmd codesize,design,naming,unusedcode 規範.  
參考:  
https://example.invalid/p/eb01b510f082  
https://example.invalid/a/1190000002521620
1. 修改完代理運行 linter.bat 
1. develop 開發, 開發完推上去. (master 禁止 push)
1. 發 merge request 到 master.
1. 找 @mars, @rory review MR.
1. merge 進 master.