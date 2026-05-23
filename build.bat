@echo off
setlocal enabledelayedexpansion
:: ============================================================================
::  build.bat — 非交互式构建脚本
::
::  用法:
::    build.bat <app-name> [--run]
::    build.bat calculator
::    build.bat calculator --run
::
::  退出码:
::    0: 全部成功
::    1: 参数错误或前置检查失败
::    2: SFC 编译失败
::    3: AOT 编译失败
::    4: 打包失败
::    5: 运行时崩溃 (--run 模式)
:: ============================================================================

:: --------------------------------------------------------------------------
:: 参数检查
:: --------------------------------------------------------------------------
if "%~1"=="" (
    echo Usage: build.bat ^<app-name^> [--run]
    echo Example: build.bat calculator
    echo           build.bat calculator --run
    exit /b 1
)
set "APP_NAME=%~1"
set "RUN_AFTER=0"
if "%~2"=="--run" set "RUN_AFTER=1"

:: --------------------------------------------------------------------------
:: 路径计算 (全部基于 %~dp0)
:: --------------------------------------------------------------------------

:: 框架根 (本文件所在目录)
for %%a in ("%~dp0.") do set "FRAMEWORK_ROOT=%%~fa"
:: 父目录 (框架根的上一级)
for %%a in ("%~dp0..") do set "PARENT_DIR=%%~fa"
:: 应用目录
set "APPS_DIR=%FRAMEWORK_ROOT%\apps"

:: 通配符查找 swoole_compile* 目录 (版本号会变, 使用通配符自动匹配)
:: 优先在框架根目录内查找, 其次在父目录查找
set "COMPILER_DIR="
for /d %%d in ("%FRAMEWORK_ROOT%\swoole_compile*") do (
    set "COMPILER_DIR=%%d"
)
if not defined COMPILER_DIR (
    for /d %%d in ("%PARENT_DIR%\swoole_compile*") do (
        set "COMPILER_DIR=%%d"
    )
)

if not defined COMPILER_DIR (
    echo [错误] 未找到 swoole_compile* 目录
    echo   已搜索: %FRAMEWORK_ROOT%\
    echo   已搜索: %PARENT_DIR%\
    echo   请确保 swoole_compiler 文件夹在框架根目录或其父目录下
    exit /b 1
)

:: 编译器路径 (从通配符匹配的目录获取)
set "PHP_CLI=%COMPILER_DIR%\php.exe"
set "SWOOLE_COMPILER=%COMPILER_DIR%\swoole_compiler.exe"
set "DLL_PHP=%COMPILER_DIR%\php8ts.dll"
set "DLL_PHPX=%COMPILER_DIR%\phpx.dll"

:: vcvarsall — 系统路径，如需修改请改此处
set "VCVARSALL=C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat"

:: --------------------------------------------------------------------------
:: 应用目录检查
:: --------------------------------------------------------------------------
set "APP_DIR=%APPS_DIR%\%APP_NAME%"

if not exist "%APP_DIR%\" (
    echo [错误] 应用目录不存在: %APP_DIR%
    exit /b 1
)
if not exist "%APP_DIR%\project.yml" (
    echo [错误] project.yml 不存在: %APP_DIR%\project.yml
    exit /b 1
)

:: --------------------------------------------------------------------------
:: 前置检查
:: --------------------------------------------------------------------------
chcp 65001 >nul 2>&1
echo.
echo ========================================
echo   Non-interactive Build
echo   应用: %APP_NAME%
echo   框架: %FRAMEWORK_ROOT%
echo   编译器: %COMPILER_DIR%
echo ========================================
echo.

if not exist "%PHP_CLI%" (
    echo [错误] PHP CLI 不存在: %PHP_CLI%
    exit /b 1
)
if not exist "%SWOOLE_COMPILER%" (
    echo [错误] Swoole Compiler 不存在: %SWOOLE_COMPILER%
    exit /b 1
)

:: --------------------------------------------------------------------------
:: 解析应用配置
:: --------------------------------------------------------------------------

:: 检测是否含 .vue 文件
set "HAS_VUE=0"
if exist "%APP_DIR%\*.vue" set "HAS_VUE=1"
for /r "%APP_DIR%" %%f in (*.vue) do set "HAS_VUE=1" 2>nul

:: 读取 project.yml 的 name 字段获取 exe 名
set "EXE_NAME=%APP_NAME%"
for /f "tokens=2 delims=: " %%a in ('findstr /r "^name:" "%APP_DIR%\project.yml" 2^>nul') do (
    set "EXE_NAME=%%~a"
)
set "OUTPUT_EXE=%EXE_NAME%.exe"

:: 获取 .vue 文件名 (用于 SFC 步骤)
set "VUE_FILE="
for %%f in ("%APP_DIR%\*.vue") do set "VUE_FILE=%%~nxf"

:: 获取 .vue 文件 basename
set "VUE_BASE="
if not "%VUE_FILE%"=="" (
    for %%f in ("%VUE_FILE%") do set "VUE_BASE=%%~nf"
)
if "%VUE_BASE%"=="" set "VUE_BASE=%EXE_NAME%"

echo [配置] EXE: %OUTPUT_EXE%
if "%HAS_VUE%"=="1" echo [配置] SFC: %VUE_FILE% -^> gen\%VUE_BASE%Component.php + gen\ComponentFactory.php
echo.

:: ====================================================================
:: Step 0: MSVC 编译环境
:: ====================================================================
echo ========================================
echo   Step 0: MSVC 编译环境
echo ========================================
echo.

if exist "%VCVARSALL%" (
    echo [初始化] 调用 vcvarsall.bat x64...
    call "%VCVARSALL%" x64 >nul 2>&1
    if !errorlevel! neq 0 (
        echo   [警告] MSVC 环境初始化失败
    ) else (
        where cl >nul 2>&1 && echo   [OK] cl.exe 可用 || echo   [警告] cl.exe 未在 PATH 中
    )
) else (
    echo   [警告] vcvarsall.bat 未找到: %VCVARSALL%
    echo   如果 AOT 编译失败, 请在 Developer Command Prompt for VS 中运行
)

where cl >nul 2>&1
if !errorlevel! neq 0 (
    echo [错误] cl.exe 未在 PATH 中
    echo.
    echo   请通过以下任一方式解决:
    echo     1. 从 "Developer Command Prompt for VS" 中运行本脚本
    echo     2. 检查 VCVARSALL 路径是否正确: %VCVARSALL%
    echo.
    exit /b 1
)
echo   [OK] cl.exe available
echo.

:: ====================================================================
:: Step 0.5: AOT 静态检查
:: ====================================================================
echo ========================================
echo   Step 0.5: AOT 静态检查
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"
"%PHP_CLI%" framework\aot-checker.php --project "%APP_DIR%" --skip direct_cpp_call
set "CHECK_EXIT=!errorlevel!"
if !CHECK_EXIT! neq 0 (
    echo.
    echo [错误] AOT Checker 发现问题，中止构建
    echo   请修复上述错误后重新构建
    exit /b 1
)
echo   [完成] AOT 静态检查通过
echo.

:: ====================================================================
:: Step 1: SFC 编译 (仅含 .vue 文件时)
:: ====================================================================
if "%HAS_VUE%"=="0" goto :skip_sfc

echo ========================================
echo   Step 1: SFC 编译 ^(Vue -^> .gen.php^)
echo ========================================
echo   Input: %VUE_FILE%
echo   Output: gen\%VUE_BASE%.gen.php
echo           gen\%VUE_BASE%Layout_gen.php
echo.

cd /d "%FRAMEWORK_ROOT%"
"%PHP_CLI%" framework\sfc-compiler.php "apps\%APP_NAME%\%VUE_FILE%"
set "SFC_EXIT=!errorlevel!"
if !SFC_EXIT! neq 0 (
    echo.
    echo [错误] SFC 编译失败, 错误码: !SFC_EXIT!
    exit /b 2
)

:: 验证输出文件
cd /d "%APP_DIR%"
if not exist "gen\%VUE_BASE%Component.php" (
    echo [警告] SFC 输出缺失: gen\%VUE_BASE%Component.php
)
if not exist "gen\ComponentFactory.php" (
    echo [警告] SFC 输出缺失: gen\ComponentFactory.php
)

echo   [完成] SFC 编译成功
echo.
goto :step2

:skip_sfc
echo ========================================
echo   Step 1: SFC 编译 - 跳过 ^(无 .vue 文件^)
echo ========================================
echo.

:: ====================================================================
:: Step 2: AOT 编译
:: ====================================================================
:step2
echo ========================================
echo   Step 2: AOT 编译 ^(PHP -^> exe^)
echo ========================================
echo   Config: apps\%APP_NAME%\project.yml
echo   Output: %FRAMEWORK_ROOT%\%OUTPUT_EXE%
echo.

:: 再次确认 cl.exe
where cl >nul 2>&1
if !errorlevel! neq 0 (
    echo [错误] cl.exe 在 SFC 步骤后丢失, 无法进行 AOT 编译
    echo   请从 Developer Command Prompt for VS 中运行本脚本
    exit /b 3
)

:: 确保 php8embed.lib 在编译器根目录 (swoole_compiler 仅搜索自身目录)
if not exist "%COMPILER_DIR%\php8embed.lib" (
    if exist "%COMPILER_DIR%\SDK\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\SDK\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] 已复制 php8embed.lib 到编译器目录
    ) else if exist "%COMPILER_DIR%\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] 已复制 php8embed.lib 到编译器目录
    ) else if exist "%COMPILER_DIR%\lib\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\lib\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] 已复制 php8embed.lib 到编译器目录
    ) else (
        echo [错误] 找不到 php8embed.lib
        echo   请将 php8embed.lib 放置在: %COMPILER_DIR%\
        exit /b 3
    )
)

cd /d "%FRAMEWORK_ROOT%"
"%SWOOLE_COMPILER%" "apps\%APP_NAME%\project.yml" -f
set "AOT_EXIT=!errorlevel!"
if !AOT_EXIT! neq 0 (
    echo.
    echo [错误] AOT 编译失败, 错误码: !AOT_EXIT!
    echo.
    echo   常见原因:
    echo     1. MSVC [cl.exe] 未找到
    echo        == 从 Developer Command Prompt for VS 运行本脚本
    echo     2. 顶层游离代码 [require_once, include, 函数外的语句]
    echo        == 所有代码必须在函数或类内部
    echo     3. 变量先使用后定义
    echo        == 确保变量有初始值
    echo     4. 变量类型被改变 [int 变 string 等]
    echo     5. 文件名含特殊字符 [仅允许 a-zA-Z0-9_]
    echo.
    exit /b 3
)

:: 验证输出 exe
if not exist "%FRAMEWORK_ROOT%\%OUTPUT_EXE%" (
    echo [错误] AOT 返回成功但未生成 exe: %FRAMEWORK_ROOT%\%OUTPUT_EXE%
    echo   请检查 project.yml 的 name 字段: 当前为 "%EXE_NAME%"
    exit /b 3
)

echo   [完成] AOT 编译成功 ^(%OUTPUT_EXE%^)
echo.

:: ====================================================================
:: Step 3: 打包
:: ====================================================================
echo ========================================
echo   Step 3: 打包 ^(exe + DLLs -^> bin/^)
echo ========================================
echo.

set "DIST_DIR=%APP_DIR%\bin"
if not exist "%DIST_DIR%\" mkdir "%DIST_DIR%" 2>nul

echo   Copying %OUTPUT_EXE% ...
copy /y "%FRAMEWORK_ROOT%\%OUTPUT_EXE%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [错误] 复制 %OUTPUT_EXE% 失败
    exit /b 4
)

echo   Copying php8ts.dll ...
copy /y "%DLL_PHP%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [错误] 复制 php8ts.dll 失败
    exit /b 4
)

echo   Copying phpx.dll ...
copy /y "%DLL_PHPX%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [错误] 复制 phpx.dll 失败
    exit /b 4
)

echo.
echo   Package contents:
echo   ----------------------------------------
for %%f in ("%DIST_DIR%\*") do echo     %%~nxf   %%~zf bytes
echo   ----------------------------------------
echo.

:: ====================================================================
:: Step 4: 可选运行验证 (仅 --run 时)
:: ====================================================================
if "%RUN_AFTER%"=="1" (
    echo ========================================
    echo   Step 4: 运行验证
    echo ========================================
    echo.
    echo [运行] 启动 %OUTPUT_EXE% 进行验证...
    start "" "%DIST_DIR%\%OUTPUT_EXE%"
    timeout /t 3 /nobreak >nul
    tasklist /FI "IMAGENAME eq %OUTPUT_EXE%" 2>nul | find /i "%OUTPUT_EXE%" >nul
    if !errorlevel! neq 0 (
        echo [错误] 进程已退出，可能崩溃
        exit /b 5
    )
    echo [OK] 进程运行正常
    taskkill /F /IM %OUTPUT_EXE% >nul 2>&1
    echo.
)

echo ========================================
echo   [OK] Build completed successfully
echo ========================================
echo.
echo   输出目录: %DIST_DIR%\
echo   运行程序: %DIST_DIR%\%OUTPUT_EXE%
echo ========================================
exit /b 0
