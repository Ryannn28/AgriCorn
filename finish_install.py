import paramiko
import os

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'
REMOTE_DIR = '/var/www/html/AgriCorn'

def run_sudo_cmd(client, cmd):
    print(f"Running: sudo {cmd}")
    stdin, stdout, stderr = client.exec_command(f"sudo -S {cmd}")
    stdin.write(PASS + '\n')
    stdin.flush()
    exit_status = stdout.channel.recv_exit_status()
    # Decode ignoring errors to avoid cp1252 charmap errors
    out = stdout.read().decode('utf-8', errors='ignore').strip()
    err = stderr.read().decode('utf-8', errors='ignore').strip()
    if out: print("STDOUT:", out[:500] + ("..." if len(out) > 500 else ""))
    if err: print("STDERR:", err[:500] + ("..." if len(err) > 500 else ""))
    return exit_status

def main():
    print("Re-connecting to VM to finish installation...")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    print("4. Continuing Python Virtual Environment setup...")
    venv_dir = f"{REMOTE_DIR}/env"
    run_sudo_cmd(client, f"{venv_dir}/bin/pip install --upgrade pip")
    run_sudo_cmd(client, f"{venv_dir}/bin/pip install tensorflow numpy pillow")
    
    print("5. Setting up the database...")
    db_setup_sql = "CREATE DATABASE IF NOT EXISTS agricorn; GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' IDENTIFIED BY ''; FLUSH PRIVILEGES;"
    stdin, stdout, stderr = client.exec_command(f"sudo -S mysql -e \"{db_setup_sql}\"")
    stdin.write(PASS + '\n')
    stdin.flush()
    stdout.channel.recv_exit_status()
    
    print("Importing agricorn.sql...")
    run_sudo_cmd(client, f"mysql agricorn < {REMOTE_DIR}/agricorn.sql")

    print("6. Configuring permissions and restarting Apache...")
    run_sudo_cmd(client, f"chown -R www-data:www-data {REMOTE_DIR}")
    run_sudo_cmd(client, f"chmod -R 755 {REMOTE_DIR}")
    run_sudo_cmd(client, "systemctl restart apache2")
    
    print("DONE! The VM has been set up successfully.")
    client.close()

if __name__ == '__main__':
    main()
