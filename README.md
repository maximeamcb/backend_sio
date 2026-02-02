# Backend

## Prerequisites

- **Web Server**: Apache with PHP
- **Database**: MySQL or MariaDB
- **PHP Extensions**: `mysqli`, `pdo_mysql`

## Deployment Steps

```bash
apt install php
apt install php-mysqli php-pdo-mysql
systemctl restart apache2
apt install mariadb-server
sudo mariadb
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'root';
FLUSH PRIVILEGES;
```

1. Configure the `.env` file with your production-like database credentials:
   ```ini
   DB_HOST=localhost
   DB_USER=your_db_user
   DB_PASS=your_db_password
   DB_NAME=backend
   ```

2. Run the following command to initialize the database and tables:
   ```bash
   ssh root@your_server_ip
   php backend/init_db.php
   ```
   *Note: Ensure the credentials in your `.env` file are correct before running this.*

