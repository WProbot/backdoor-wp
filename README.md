# Backdoor WP

This script is used to create, delete, edit, or log in in a WordPress installation when you do not have dashboard access but only SFTP/FTPS/FTP access.
You can also (since 3.1) deactivate plugins without being logged in (because you can't if it's broken).
Just upload, run it and read.

# Styles

Red sign !

![screenshot](/assets/screenshots/screeny.png?raw=true "screenshot")

# Requirements

BEFORE ANYTHING, I'm not gonna allow PHP under 7.0 in 2019.

## Fork

I made some PR to [the base repository](https://github.com/BoiteAWeb/SecuPress-Backdoor-User), some were accepted, some not.
I did not agree with the author on those which were rejected so the best way is to fork instead of trying to make him replace his code with mine.
I've added some things... and I've deleted all the CSS stuffs that are kinda heavy (all bootstrap etc) even if I understand the branding need.

Not pretending this is better, but lighter and it fixes some bugs.