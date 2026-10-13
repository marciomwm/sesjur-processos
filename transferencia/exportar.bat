@echo off
rem =====================================================================
rem  SESJUR - Exportar para outro computador
rem
rem  Gera um pacote com os arquivos do sistema e o banco com os dados:
rem    <Area de Trabalho>\SESJUR-transferencia-AAAAMMDD_HHMMSS\
rem      gestao-juridica\          arquivos do sistema (inclui config.php)
rem      banco\gestao_juridica.sql dump completo do banco
rem      instalar.bat              instalador para o computador novo
rem    e o mesmo conteudo compactado em SESJUR-transferencia-....zip
rem
rem  Uso: de dois cliques com o Laragon iniciado (MySQL ligado).
rem  Variaveis opcionais (antes de executar, no prompt):
rem    set LARAGON=D:\laragon      raiz do Laragon (padrao C:\laragon)
rem    set DB=outro_banco          nome do banco (padrao gestao_juridica)
rem    set SAIDA=D:\pacotes        pasta onde gerar o pacote
rem    set MYSQL_PWD=senha         se o usuario root do MySQL tiver senha
rem
rem  ATENCAO: o pacote contem dados pessoais e de processos. Transporte
rem  por meio seguro e apague as copias intermediarias depois.
rem =====================================================================
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title SESJUR - Exportar

if not defined LARAGON set "LARAGON=C:\laragon"
if not defined DB set "DB=gestao_juridica"
set "ORIGEM=%LARAGON%\www\gestao-juridica"

echo.
echo  SESJUR - Exportação para outro computador
echo  =========================================
echo.

if not exist "%ORIGEM%\app\bootstrap.php" (
    echo  [ERRO] Sistema não encontrado em "%ORIGEM%".
    echo         Ajuste a variável LARAGON e execute de novo.
    goto :falha
)

rem --- MySQL do Laragon (a versao mais recente instalada) ---
set "MYSQLBIN="
for /d %%D in ("%LARAGON%\bin\mysql\*") do if exist "%%D\bin\mysqldump.exe" set "MYSQLBIN=%%D\bin"
if not defined MYSQLBIN (
    echo  [ERRO] mysqldump.exe não encontrado em "%LARAGON%\bin\mysql".
    goto :falha
)

"%MYSQLBIN%\mysql.exe" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
    echo  [ERRO] Não foi possível conectar ao MySQL.
    echo         Inicie o Laragon ^(botão "Iniciar Tudo"^) e execute de novo.
    echo         Se o root tiver senha:  set MYSQL_PWD=sua_senha
    goto :falha
)

set "EXISTE="
for /f %%B in ('call "%MYSQLBIN%\mysql.exe" -u root -N -e "SHOW DATABASES LIKE '%DB%'"') do set "EXISTE=%%B"
if not defined EXISTE (
    echo  [ERRO] O banco "%DB%" não existe neste MySQL.
    goto :falha
)

rem --- Pasta de saida: Area de Trabalho (mesmo redirecionada ao OneDrive) ---
for /f "delims=" %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "TS=%%T"
if not defined SAIDA for /f "delims=" %%P in ('powershell -NoProfile -Command "[Environment]::GetFolderPath('Desktop')"') do set "SAIDA=%%P"
set "PACOTE=%SAIDA%\SESJUR-transferencia-%TS%"

if exist "%PACOTE%" (
    echo  [ERRO] A pasta "%PACOTE%" já existe.
    goto :falha
)
mkdir "%PACOTE%\banco" || goto :falha

echo  [1/4] Exportando o banco "%DB%"...
"%MYSQLBIN%\mysqldump.exe" -u root --default-character-set=utf8mb4 --single-transaction --routines --triggers "%DB%" --result-file="%PACOTE%\banco\gestao_juridica.sql"
if errorlevel 1 (
    echo  [ERRO] Falha ao exportar o banco.
    goto :falha
)

echo  [2/4] Copiando os arquivos do sistema...
rem Ficam de fora: backups antigos do banco, zips antigos da extensao e .git.
robocopy "%ORIGEM%" "%PACOTE%\gestao-juridica" /E /XF backup_antes_*.sql backup_dados_*.sql *.zip /XD .git /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 (
    echo  [ERRO] Falha ao copiar os arquivos.
    goto :falha
)

echo  [3/4] Incluindo o instalador...
copy /y "%~dp0instalar.bat" "%PACOTE%\instalar.bat" >nul
copy /y "%~dp0LEIA-ME.txt" "%PACOTE%\LEIA-ME.txt" >nul

echo  [4/4] Compactando...
rem tar.exe do Windows 10+ gera .zip com -a e aceita caminhos longos.
tar -a -c -f "%PACOTE%.zip" -C "%PACOTE%" . >nul 2>&1
if errorlevel 1 (
    if exist "%PACOTE%.zip" del /q "%PACOTE%.zip"
    echo  [AVISO] Não foi possível gerar o .zip; leve a pasta diretamente.
)

echo.
echo  Pronto. Pacote gerado em:
echo    %PACOTE%
if exist "%PACOTE%.zip" echo    %PACOTE%.zip
echo.
echo  Leve o .zip ^(ou a pasta^) para o computador novo, extraia e
echo  execute instalar.bat com o Laragon iniciado.
echo.
echo  ATENÇÃO: o pacote contém dados pessoais e de processos.
echo  Transporte por meio seguro e apague as cópias depois.
echo.
if not defined SEM_PAUSA pause
exit /b 0

:falha
echo.
echo  A exportação NÃO foi concluída.
echo.
if not defined SEM_PAUSA pause
exit /b 1
