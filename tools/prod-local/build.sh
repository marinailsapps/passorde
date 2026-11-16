#!/bin/sh
# Builds the production images for the local rehearsal (docs/RUNBOOK.md "Production
# stack on a laptop"), the way an installing developer builds theirs: the app image
# with the installation profile handed to the asset build (docs/RUNBOOK.md
# "Installation profile"), and the backup image. Usage, from the repository root:
#
#   PASSORDE_PROFILE_DIR=<profile directory> sh tools/prod-local/build.sh
#   PASSORDE_PROFILE_DIR=none sh tools/prod-local/build.sh      # the neutral product
#
# TAG (default prod-local) and IMAGE / BACKUP_IMAGE name the images.
set -eu

profile=${PASSORDE_PROFILE_DIR:?set PASSORDE_PROFILE_DIR to the profile directory, or to none for the neutral product}
tag=${TAG:-prod-local}
image=${IMAGE:-passorde}
backup_image=${BACKUP_IMAGE:-passorde-backup}

if [ "$profile" = none ]; then
	set -- --build-arg PASSORDE_PROFILE=none
else
	[ -f "$profile/profile.php" ] || { echo "build: $profile holds no profile.php" >&2; exit 1; }
	set -- --build-context "profile=$profile" --build-arg PASSORDE_PROFILE=/profile
fi

docker build -f docker/php/Dockerfile --target production \
	--build-arg APP_VERSION=local \
	"$@" -t "$image:$tag" .
docker build -f docker/backup/Dockerfile -t "$backup_image:$tag" .

# What the assets were built with (the stamp passorde:check-profile compares with the running profile).
docker run --rm --entrypoint cat "$image:$tag" public/build/profile.json
