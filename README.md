Git Subsplit GitHub WebHook
===========================

[GitHub][1] WebHook for Git subsplits managed by [git-subsplit][2].

Automates the process of keeping one-way read-only subtree splits up to date
with the source repository.

The WebHook works in two parts, a web listener and a worker. The web listener
sends requets to worker by a unix socket.

The worker should always be run as `root`. 
- It will interact with the system's git as the user defined in 
`SUBSPLIT_DEFAULT_USER` env variable (default to `debian` user).
**This means that this user should have its key added to the appropriate
GitHub accounts.**
- It will interact with the system's web server as the user defined in
`SUBSPLIT_SOCKET_USER` env variable (default to `www-data` user).

During testing it would make sense to run the worker manually. For production
deployments it would probably make more sense to write a small `systemd` file.


Usage
-----

### git-subsplit

Ensure that [git-subsplit][2] is installed correctly. If is not available
in your version of git (likely true for versions older than 1.7.11)
please install it manually from [here][3].

You should initialize subsplit with a git repository:

    mkdir -p /home/myuser/.subsplit/myproject && cd /home/myuser/.subsplit/myproject
    git subsplit init git@github.com:orga/repo.git

It will create a `.subsplit` working directory that you will use later.

### Installation

    git clone git@github.com:qboot/dflydev-git-subsplit-github-webhook.git webhook
    cd webhook

### Configure

Copy `config.json.dist` to `config.json` and edit it accordingly. Please make sure
to pay special attention to setting `working-directory` correctly.
Don't forget to change the `webhook-secret` to secure your webhook.

### Web Server

Setup a virtual host pointing to `web/` as its docroot. Assuming the virtual host
is **webhook.example.com**, test the WebHook by visiting the following URL:
**http://webhook.example.com/index.php**

### Worker

Start the worker by running `sudo php bin/worker.php`.

### GitHub

From your repository go to **Settings** / **Service Hooks** / **WebHook URLs**.
Enter the URL to your WebHook and your secret. Then click **Update Settings**.

Click **WebHook URLs** again and click **Test Hook**.

If everything is setup correctly the Worker should give you some sort of feedback.


Configuration
-------------

### Example

```
{
    "working-directory": "/home/myuser/.subsplit",
    "webhook-secret": "ThisTokenIsNotSoSecretChangeIt",
    "projects": {
        "project-1": {
            "url": "git@github.com:orga/private-repo.git",
            "splits": [
                "src/public:git@github.com:orga/public-repo.git"
            ],
            "heads": [
                "master",
                "2.0"
            ],
            "tags": [
                "v1.0.0",
                "v1.0.3"
            ]
        }
    }
}
```

### Schema

#### working-directory

*String. Default: None. **Required.***

The directory in which the subsplits will be processed. This is more or less
a temporary directory in which all projects will have their subsplit initialized.

#### webhook-secret

*String. Default: "ThisTokenIsNotSoSecretChangeIt". **Required.***

This is a secret string that should be unique to your webhook. It's used to secure communication between the webhook and github.
You can use a *secret generator* if you want a strong string.

#### projects

*Object. **Required**.*

An object whose keys are project names and values are a project description
object.

Project names should only contain a-z, A-Z, 0-9, `.`, `_`, and `-`.

Each project description object can have the following properties:

 * **url**:
   The URL for the project. The WebHook will check each incoming request's
   `url` property against each project's listed `url` property to determine
   which project the request is for.
   
   This URL should be like: **git@github.com:orga/repo.git**
   
 * **repository-url**:
   The URL that `git` will use to check out the project. This setting is
   optional. If it is not defined the repository URL will be read from the
   incoming request.
 * **splits**:
   An array of subsplit definitions as defined by [git-subsplit][2].
   The pattern for the splits is `${subPath}:${url}`.

 * **heads** *(optional)*:
   An array of heads. If specified, git-subsplit will only sync these branches, 
   no matter which branches the upstream repository knows about.

* **tags** *(optional)*:
   An array of tags. If specified, git-subsplit will only sync these tags, no 
   matter which tags the upstream repository knows about.

* **options** *(optional)*:
   An object containing options.
   `"no-tags": true` disable syncing of any tags.
   `"no-heads": true` disable syncing of any heads.

License
-------

MIT, see LICENSE.


Community
---------

If you have questions or want to help out, join us in the
**#dflydev** channel on irc.freenode.net.


Not Invented Here
-----------------

This project is based heavily on work originally done by [igorw][4].
Thanks Igor. :)


[1]: https://github.com
[2]: https://github.com/dflydev/git-subsplit
[3]: https://github.com/apenwarr/git-subtree
[4]: https://igor.io
