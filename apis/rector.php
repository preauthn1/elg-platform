<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\Equal\UseIdenticalOverEqualWithSameTypeRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveVoidDocblockFromMagicMethodRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php84\Rector\Class_\DeprecatedAnnotationToDeprecatedAttributeRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictStringParamConcatRector;
use Rector\TypeDeclaration\Rector\Empty_\EmptyOnNullableObjectToInstanceOfRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;
use Rector\ValueObject\PhpVersion;
use RectorLaravel\Rector\Class_\FillablePropertyToFillableAttributeRector;
use RectorLaravel\Rector\Class_\GuardedPropertyToGuardedAttributeRector;
use RectorLaravel\Rector\Class_\HiddenPropertyToHiddenAttributeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\ClassMethod\MakeModelAttributesAndScopesProtectedRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Rector\FuncCall\ThrowIfAndThrowUnlessExceptionsToUseClassStringRector;
use RectorLaravel\Rector\If_\ThrowIfRector;
use RectorLaravel\Rector\MethodCall\WhereToWhereLikeRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        // 跳過 vendor 目錄
        __DIR__ . '/vendor',
        // 不自動加 #[Override] 屬性（避免對現有大量方法批次修改）
        AddOverrideAttributeToOverriddenMethodsRector::class,
        // 不將 throw_if() / throw_unless() 的例外參數轉為 class-string
        ThrowIfAndThrowUnlessExceptionsToUseClassStringRector::class,
        // 不將 if (...) { throw ... } 轉換為 throw_if()
        ThrowIfRector::class,
        // == 與 !== 在明確型別情境下已足夠，不強制改為 === / !==
        UseIdenticalOverEqualWithSameTypeRector::class,
        // 保留原有變數賦值寫法，不移除「未使用的賦值」
        RemoveUnusedVariableAssignRector::class,
        // 保留 empty() 用法，不強制替換為嚴格型別判斷
        DisallowedEmptyRuleFixerRector::class,
        // 保留 empty() 對 nullable object 的用法，不轉換為 instanceof 檢查
        EmptyOnNullableObjectToInstanceOfRector::class,
        // !$var 可能是多種假值（null/false/0/''），不強制轉換為 instanceof 檢查
        ExplicitBoolCompareRector::class,
        // 不強制在 catch 重新 throw 時帶入 previous exception（可能改變例外鏈行為）
        ThrowWithPreviousExceptionRector::class,
        // 不自動加 readonly，需人工確認屬性不會在 constructor 外被重新賦值
        ReadOnlyPropertyRector::class,
        ReadOnlyClassRector::class,
        // Carbon::now() → Date::now()，兩者 API 不完全相同，需人工評估
        CarbonToDateFacadeRector::class,
        // 將屬性宣告移入 constructor 參數，結構變動大，增加 code review 難度
        ClassPropertyAssignToConstructorPromotionRector::class,
        // 從賦值推斷屬性型別，可能推斷錯誤（如多型態賦值場景）
        TypedPropertyFromAssignsRector::class,
        // 嚴格型別推斷系列，可能在動態型別場景產生誤判
        StrictArrayParamDimFetchRector::class,
        StrictStringParamConcatRector::class,
        // 低價值 docblock 整理：產生大量 diff noise，但不影響執行邏輯
        RemoveUselessReturnTagRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessVarTagRector::class,
        RemoveVoidDocblockFromMagicMethodRector::class,
        // @deprecated 轉 PHP attribute，屬樣式偏好，非急迫
        DeprecatedAnnotationToDeprecatedAttributeRector::class,
        // 排序具名參數，純粹美觀，不改變邏輯
        SortCallLikeNamedArgsRector::class,
        // 屬性轉 PHP Attribute 寫法：語法轉換量大，待評估後再統一處理
        GuardedPropertyToGuardedAttributeRector::class,
        FillablePropertyToFillableAttributeRector::class,
        HiddenPropertyToHiddenAttributeRector::class,
        TablePropertyToTableAttributeRector::class,
        MakeModelAttributesAndScopesProtectedRector::class,
    ])
    // 明確指定目標 PHP 版本
    ->withPhpVersion(PhpVersion::PHP_84)
    // PHP 8.4 所有現代化語法規則（含 readonly、enum、property hooks 等）
    ->withPhpSets(php84: true)
    // Rector 內建規則集：Dead Code 清理、品質提升、型別宣告補全、Early Return 重構
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    // 【官方推薦】從 composer.json 自動偵測 Laravel 版本並套用對應升級規則
    // 升級 Laravel 後只需更新 composer.json，無需手動調整此設定
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true)
    // Laravel 額外品質規則集
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        // 將 if (...) { abort(...) } 轉換為 abort_if() 等 Laravel 慣用寫法
        LaravelSetList::LARAVEL_IF_HELPERS,
        // 自動現代化測試斷言與棄用方法
        LaravelSetList::LARAVEL_TESTING,
    ])
    // 自動移除遺留的 dd() / dump() / var_dump() 除錯呼叫
    ->withConfiguredRule(RemoveDumpDataDeadCodeRector::class, ['dd', 'dump', 'var_dump'])
    // where('col', 'like', '%val%') → whereLike('col', '%val%')（MySQL 8.0，非 Postgres）
    ->withConfiguredRule(WhereToWhereLikeRector::class, [
        WhereToWhereLikeRector::USING_POSTGRES_DRIVER => false,
    ])
    // 啟用並行處理，加速大型專案分析
    ->withParallel()
    // 快取至 Laravel 框架快取目錄，加速重複執行
    ->withCache(cacheDirectory: __DIR__ . '/storage/framework/cache/rector');
