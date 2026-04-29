import paramiko
import os
import zipfile

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'

def zipdir(path, ziph):
    for root, dirs, files in os.walk(path):
        for file in files:
            file_path = os.path.join(root, file)
            arcname = os.path.relpath(file_path, os.path.dirname(path))
            ziph.write(file_path, arcname)

def main():
    print("Zipping local .git directory...")
    with zipfile.ZipFile('git_backup.zip', 'w', zipfile.ZIP_DEFLATED) as zipf:
        zipdir('.git', zipf)
    print("Zip created: git_backup.zip")

    print("Connecting to VM...")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    print("Uploading git_backup.zip...")
    sftp = client.open_sftp()
    sftp.put('git_backup.zip', '/tmp/git_backup.zip')
    sftp.close()

    def run_sudo(cmd):
        print(f"Running: {cmd}")
        stdin, stdout, stderr = client.exec_command(f"sudo -S {cmd}")
        stdin.write(PASS + '\n')
        stdin.flush()
        stdout.channel.recv_exit_status()

    print("Installing git and unzipping repository on VM...")
    run_sudo("apt-get install -y git unzip")
    run_sudo("unzip -o /tmp/git_backup.zip -d /var/www/html/AgriCorn/")
    run_sudo("chown -R www-data:www-data /var/www/html/AgriCorn/.git")
    run_sudo("rm /tmp/git_backup.zip")

    # Run git status to verify
    stdin, stdout, stderr = client.exec_command("cd /var/www/html/AgriCorn && git status")
    print("VM Git Status:\n" + stdout.read().decode())

    client.close()
    
    # Clean up local zip
    if os.path.exists('git_backup.zip'):
        os.remove('git_backup.zip')
    print("Git repository successfully initialized on the VM!")

if __name__ == '__main__':
    main()
