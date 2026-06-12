# WorkPulse

A responsive workforce management dashboard for multi-project teams.

## Included

- Face attendance workflow with 100-metre project geofence verification
- Employee and project assignment management
- Monthly salary and employee advance tracking
- Role incentives for telecallers, sales executives, and managers
- Telecaller visit incentive slabs
- Senior manager deal-share incentive payable on service payment receipt
- Attendance, payroll, incentive, and MIS report views

## Run locally

```powershell
php -S 127.0.0.1:8765
```

Then open `http://127.0.0.1:8765`.

## Deployment

Upload `index.php` to any PHP-enabled hosting directory. This version is a polished interactive front-end prototype; production face recognition, GPS validation, authentication, and persistent payroll data require secure backend APIs and a database.
