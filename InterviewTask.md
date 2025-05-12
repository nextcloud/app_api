Interview Task Report https://github.com/nextcloud/app_api/issues/519

From my understanding, the app_api codebase is mainly a devOps tool used for managing other applications 


I've struggled with getting the app up and running. I followed the documentation here: https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/index.html

While following the documentation, I couldn't find the script that this command executes: `./occ app:enable --force app_api` Perhaps this is in the `server` codebase instead? if that's the case, it isn't clear in this documentation

I However, did manage to get the environment up and running at http://nextcloud.local using the `docker-socket-proxy` repository. 

### Solution

It took my quite some time to understand the codebase, but I managed to have a fair idea of it

- In the `lib` folder, I introduced the following method in...

 - `/lib/DeployActions/DockerActions.php` The aim is to call docker's `/images/prune` API

Next, I implemented the following 

- `lib/BackgroundJob/DockerImageCleanupJob.php`

With these in place, I am struggling to understand how they tie into the entire nextcloud ecosystem and how to actually debug the code to see it in action. 

this is because with the development environment running, and after I login, I couldn't access `http://nextcloud.local/index.php/settings/admin/app_api` due to permission issues.

With this, exploration, I need help with some more concrete walkthrough of the codebases and how they work together and to understand how to properly introduce my changes and test them.

