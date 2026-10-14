@echo off
rem Runs the DSS engine (EOQ / Safety Stock / ROP / K-Means / reorder alerts).
rem Schedule it monthly (run once from an admin prompt):
rem   schtasks /Create /SC MONTHLY /D 1 /ST 02:00 /TN "RfourL DSS Run" /TR "C:\full\path\to\tools\run_dss.bat"
rem The ml-service (python app.py) must be running; otherwise cached clusters are used.
cd /d "%~dp0..\backend-ci4"
php spark dss:run >> writable\logs\dss_run_task.log 2>&1
