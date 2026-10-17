@echo off
REM ---------------------------------------------------------------------------
REM Lanza el dispatcher de campanas. Pensado para el Programador de tareas de
REM Windows, ejecutandose cada minuto.
REM
REM Alta de la tarea (una sola vez, en PowerShell como administrador):
REM
REM   $accion  = New-ScheduledTaskAction -Execute "C:\xampp\htdocs\project\bin\dispatch-task.cmd"
REM   $inicio  = New-ScheduledTaskTrigger -Once -At (Get-Date) `
REM                -RepetitionInterval (New-TimeSpan -Minutes 1)
REM   Register-ScheduledTask -TaskName "ContactCenter-Dispatcher" `
REM     -Action $accion -Trigger $inicio -Description "Procesa campanas programadas y la cola de envios"
REM
REM Comprobacion:   Get-ScheduledTask -TaskName "ContactCenter-Dispatcher"
REM Ejecutar ya:    Start-ScheduledTask -TaskName "ContactCenter-Dispatcher"
REM Ver el log:     type C:\xampp\htdocs\project\storage\logs\dispatcher.log
REM ---------------------------------------------------------------------------

setlocal

REM Raiz del proyecto (carpeta padre de bin\)
set "PROJECT_DIR=%~dp0.."
cd /d "%PROJECT_DIR%"

REM Ajusta la ruta de php.exe si tu XAMPP esta en otra unidad
set "PHP_BIN=C:\xampp\php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

if not exist "storage\logs" mkdir "storage\logs"

"%PHP_BIN%" bin\dispatch-campaigns.php >> "storage\logs\dispatcher.log" 2>&1

endlocal
