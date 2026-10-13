@echo off
rem =====================================================================
rem  SESJUR - Instalar em um computador com Laragon
rem
rem  Execute a partir da pasta do pacote gerado por exportar.bat, que deve
rem  conter:  gestao-juridica\  e  banco\gestao_juridica.sql
rem
rem  O que faz:
rem    1. confere PHP (8.1+, pdo_mysql, mbstring) e MySQL do Laragon;
rem    2. se ja existir um banco gestao_juridica, faz backup dele e pede
rem       confirmacao antes de substituir;
rem    3. importa o banco (utf8mb4, preservando acentos);
rem    4. se ja existir C:\laragon\www\gestao-juridica, renomeia para
rem       gestao-juridica_anterior_AAAAMMDD_HHMMSS (nada e apagado);
rem    5. copia os arquivos e confere a conexao do sistema com o banco.
rem
rem  Variaveis opcionais (antes de executar, no prompt):
rem    set LARAGON=D:\laragon      raiz do Laragon (padrao C:\laragon)
rem    set MYSQL_PWD=senha         se o usuario root do MySQL tiver senha
rem                                (ajuste tambem app\config\config.php)
rem =====================================================================
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title SESJUR - Instalar

if not defined LARAGON set "LARAGON=C:\laragon"
if not defined DB set "DB=gestao_juridica"
if not defined WWW set "WWW=%LARAGON%\www"
set "PACOTE=%~dp0"
set "PACOTE=%PACOTE:~0,-1%"
set "DESTINO=%WWW%\gestao-juridica"
set "DUMP=%PACOTE%\banco\gestao_juridica.sql"

echo.
echo  SESJUR - Instalação
echo  ===================
echo.

if not exist "%PACOTE%\gestao-juridica\app\bootstrap.php" (
    echo  [ERRO] Pasta "gestao-juridica" não encontrada ao lado deste instalador.
    echo         Extraia o pacote inteiro e execute instalar.bat de dentro dele.
    goto :falha
)
if not exist "%DUMP%" (
    echo  [ERRO] Arquivo do banco não encontrado: "%DUMP%"
    goto :falha
)
if not exist "%LARAGON%\bin" (
    echo  [ERRO] Laragon não encontrado em "%LARAGON%".
    echo         Instale o Laragon ou ajuste a variável LARAGON.
    goto :falha
)

rem --- Localiza PHP e MySQL do Laragon (versao mais recente instalada) ---
set "PHP="
for /d %%D in ("%LARAGON%\bin\php\*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
set "MYSQLBIN="
for /d %%D in ("%LARAGON%\bin\mysql\*") do if exist "%%D\bin\mysql.exe" set "MYSQLBIN=%%D\bin"
if not defined PHP (
    echo  [ERRO] PHP não encontrado em "%LARAGON%\bin\php".
    goto :falha
)
if not defined MYSQLBIN (
    echo  [ERRO] MySQL não encontrado em "%LARAGON%\bin\mysql".
    goto :falha
)

echo  [1/5] Conferindo requisitos...
"%PHP%" -r "exit(version_compare(PHP_VERSION, '8.1.0', '>=') ? 0 : 1);"
if errorlevel 1 (
    echo  [ERRO] O SESJUR exige PHP 8.1 ou mais novo. Versão encontrada:
    "%PHP%" -r "echo '         ', PHP_VERSION, PHP_EOL;"
    echo         No Laragon: menu PHP ^> Versão, ou instale uma versão nova.
    goto :falha
)
"%PHP%" -r "exit(extension_loaded('pdo_mysql') && extension_loaded('mbstring') ? 0 : 1);"
if errorlevel 1 (
    echo  [ERRO] Ative as extensões pdo_mysql e mbstring do PHP
    echo         ^(Laragon: menu PHP ^> Extensões^).
    goto :falha
)
"%MYSQLBIN%\mysql.exe" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
    echo  [ERRO] Não foi possível conectar ao MySQL.
    echo         Inicie o Laragon ^(botão "Iniciar Tudo"^) e execute de novo.
    echo         Se o root tiver senha:  set MYSQL_PWD=sua_senha
    goto :falha
)
for /f "delims=" %%V in ('call "%MYSQLBIN%\mysql.exe" -u root -N -e "SELECT VERSION()"') do set "VERSAO_MYSQL=%%V"
for /f "delims=" %%V in ('call "%PHP%" -r "echo PHP_VERSION;"') do set "VERSAO_PHP=%%V"
echo         PHP %VERSAO_PHP% · MySQL %VERSAO_MYSQL%

for /f "delims=" %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "TS=%%T"

rem --- Banco existente: backup e confirmacao ---
set "EXISTE="
for /f %%B in ('call "%MYSQLBIN%\mysql.exe" -u root -N -e "SHOW DATABASES LIKE '%DB%'"') do set "EXISTE=%%B"
if defined EXISTE (
    echo.
    echo  [ATENÇÃO] Já existe um banco "%DB%" neste computador.
    echo      Fazendo backup dele antes de qualquer alteração...
    "%MYSQLBIN%\mysqldump.exe" -u root --default-character-set=utf8mb4 --single-transaction --routines --triggers "%DB%" --result-file="%PACOTE%\backup_banco_existente_%TS%.sql"
    if errorlevel 1 (
        echo  [ERRO] Falha no backup do banco existente. Nada foi alterado.
        goto :falha
    )
    echo      Backup salvo em: %PACOTE%\backup_banco_existente_%TS%.sql
    echo.
    set "RESPOSTA="
    set /p "RESPOSTA=     Substituir o banco existente pelos dados do pacote? (S/N): "
    if /i not "!RESPOSTA!"=="S" (
        echo      Instalação cancelada. Nada foi alterado.
        goto :falha
    )
)

echo  [2/5] Importando o banco "%DB%" (pode levar alguns minutos)...
"%MYSQLBIN%\mysql.exe" -u root -e "DROP DATABASE IF EXISTS `%DB%`; CREATE DATABASE `%DB%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto :falha_banco
"%MYSQLBIN%\mysql.exe" -u root --default-character-set=utf8mb4 "%DB%" < "%DUMP%"
if errorlevel 1 goto :falha_banco

echo  [3/5] Preservando instalação anterior (se houver)...
if exist "%DESTINO%" (
    move "%DESTINO%" "%WWW%\gestao-juridica_anterior_%TS%" >nul
    if errorlevel 1 (
        echo  [ERRO] Não foi possível renomear "%DESTINO%".
        echo         Feche programas que usem a pasta ^(editor, Explorer^) ou pare o Laragon e tente de novo.
        goto :falha
    )
    echo         Anterior movida para gestao-juridica_anterior_%TS%
)

echo  [4/5] Copiando os arquivos do sistema...
robocopy "%PACOTE%\gestao-juridica" "%DESTINO%" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 (
    echo  [ERRO] Falha ao copiar os arquivos.
    goto :falha
)
if not exist "%DESTINO%\app\config\config.php" (
    copy /y "%DESTINO%\app\config\config.example.php" "%DESTINO%\app\config\config.php" >nul
    echo         config.php criado a partir do modelo: revise usuário e senha do banco.
)

echo  [5/5] Conferindo a conexão do sistema com o banco...
"%PHP%" -r "require '%DESTINO:\=/%/app/bootstrap.php'; $n = App\Core\Database::conexao()->query('SELECT COUNT(*) FROM usuarios WHERE ativo = 1')->fetchColumn(); echo '         Conexão ok: ', $n, ' usuário(s) ativo(s).', PHP_EOL;"
if errorlevel 1 (
    echo  [AVISO] O sistema não conseguiu conectar ao banco.
    echo          Revise "db" em %DESTINO%\app\config\config.php
)

echo.
echo  Instalação concluída.
echo.
echo  Acesse:  http://localhost/gestao-juridica/public/
echo  Entre com os mesmos usuários e senhas do computador de origem.
echo.
echo  Extensão do Chrome ^(extração do SIGADOC/PJe^):
echo    chrome://extensions ^> Modo do desenvolvedor ^> Carregar sem compactação
echo    e escolha a pasta  %DESTINO%\chrome-extension
echo.
if not defined SEM_PAUSA (
    start "" "http://localhost/gestao-juridica/public/"
    pause
)
exit /b 0

:falha_banco
echo  [ERRO] Falha ao importar o banco.
if defined EXISTE echo         Para restaurar o anterior: mysql -u root %DB% ^< "%PACOTE%\backup_banco_existente_%TS%.sql"
goto :falha

:falha
echo.
echo  A instalação NÃO foi concluída.
echo.
if not defined SEM_PAUSA pause
exit /b 1
