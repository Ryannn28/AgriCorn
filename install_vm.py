import paramiko
import os

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'
LOCAL_DIR = r'c:\xampp\htdocs\AgriCorn'
REMOTE_DIR = '/var/www/html/AgriCorn'

def run_sudo_cmd(client, cmd):
    print(f"Running: sudo {cmd}")
    stdin, stdout, stderr = client.exec_command(f"sudo -S {cmd}")
    stdin.write(PASS + '\n')
    stdin.flush()
    exit_status = stdout.channel.recv_exit_status()
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    if out: print("STDOUT:", out[:500] + ("..." if len(out) > 500 else ""))
    if err: print("STDERR:", err[:500] + ("..." if len(err) > 500 else ""))
    return exit_status

def sftp_upload_dir(sftp, local_dir, remote_dir):
    try:
        sftp.mkdir(remote_dir)
    except IOError:
        pass

    for item in os.listdir(local_dir):
        if item in ['.git', '__pycache__', 'env']:
            continue
        local_path = os.path.join(local_dir, item)
        remote_path = f"{remote_dir}/{item}"
        
        if os.path.isfile(local_path):
            print(f"Uploading {item}...")
            sftp.put(local_path, remote_path)
        elif os.path.isdir(local_path):
            sftp_upload_dir(sftp, local_path, remote_path)

def main():
    print("Connecting to VM...")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    print("1. Installing system dependencies (this may take a few minutes)...")
    run_sudo_cmd(client, "apt-get update")
    # Using DEBIAN_FRONTEND=noninteractive to avoid prompt dialogs
    run_sudo_cmd(client, "DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 mariadb-server php libapache2-mod-php php-mysql php-cli php-gd php-curl python3 python3-pip python3-venv unzip")
    
    print("2. Preparing web directory...")
    run_sudo_cmd(client, f"mkdir -p {REMOTE_DIR}")
    run_sudo_cmd(client, f"chown -R {USER}:{USER} /var/www/html")

    print("3. Uploading project files...")
    sftp = client.open_sftp()
    sftp_upload_dir(sftp, LOCAL_DIR, REMOTE_DIR)
    sftp.close()

    print("4. Setting up Python Virtual Environment and TensorFlow...")
    venv_dir = f"{REMOTE_DIR}/env"
    run_sudo_cmd(client, f"python3 -m venv {venv_dir}")
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
