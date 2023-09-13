# Nextcloud AppAPI

[![Tests - Deploy](https://github.com/cloud-py-api/app_api/actions/workflows/tests-deploy.yml/badge.svg)](https://github.com/cloud-py-api/app_api/actions/workflows/tests-deploy.yml)
[![Tests](https://github.com/cloud-py-api/app_api/actions/workflows/tests.yml/badge.svg)](https://github.com/cloud-py-api/app_api/actions/workflows/tests.yml)
[![Docs](https://github.com/cloud-py-api/app_api/actions/workflows/docs.yml/badge.svg)](https://cloud-py-api.github.io/app_api/)


The App Ecosystem Project is an exciting initiative that aims to revolutionize the way applications are developed for Nextcloud. 

Unlike the traditional approach that limits developers to using PHP, this innovative ecosystem empowers them 
to create applications using a variety of programming languages, opening up a world of possibilities.

The core concept of this project is built upon four main factors:

1. **Improved Stability:** This ecosystem commits to maintaining API stability over extended periods with the help of libraries that use it APIs. 
This ensures a more reliable and consistent user experience as developers won't need to constantly update their apps with each new release.

2. **Enhanced Security:** Unlike the older method that allowed working with Nextcloud's core code directly, this approach
emphasizes a more specialized API scopes and a new way of authorization.
This results in greater app stability and a more focused user experience.

3. **Robust Computing Potential:** The ecosystem is designed to accommodate resource-intensive tasks. 
Developers can seamlessly integrate heavy computational functionalities, including advanced machine learning models, and run them on external hardware.

4. **Easy for Community Use:** The project aims to attract a diverse and larger developer community by enabling app creation 
across various programming languages and providing a well-documented, persistent, and user-friendly API.
Collaboration and contributions from the community are encouraged to drive innovations in the open-source space.

### Project Stage

The current stage of the project involves its development phase, where the groundwork is being laid for this innovative app ecosystem. 
The project has achieved several significant milestones, including the completion of an authentication system for external 
applications, implementation of deployment methods, and enhanced application confidence through API group specifications.

The future roadmap is well-defined, with plans for external UI integration, advancements in the Python library to support the Talk API, 
and seamless integration with Nextcloud AIO. 
The development process is divided between two repositories: one for the App Ecosystem itself and another for developing 
applications using Python for Nextcloud.

The project actively seeks feedback, ideas, and feature requests from the community, inviting developers, administrators, 
and individuals with innovative ideas to collaborate and shape the future of this exciting project.

### Libraries

#### For now there is only one official [Python library](https://github.com/cloud-py-api/nc_py_api).

_If you wish to develop a library for any other language, such as **Go**, **Ruby**, or whatever else, we will gladly help and assist you._

## Documentation

- [Documentation](https://cloud-py-api.github.io/app_api/)
	- [Installation](https://cloud-py-api.github.io/app_api/Installation.html)
	- [Creation of Deploy Daemon](https://cloud-py-api.github.io/app_api/ManagingExternalApplications.html)
	- [Managing External Applications](https://cloud-py-api.github.io/app_api/CreationOfDeployDaemon.html)
- [Technical Details](https://cloud-py-api.github.io/app_api/tech_details/index.html)
	- [Concepts](https://cloud-py-api.github.io/app_api/Concepts.html)
	- [Api Scopes](https://cloud-py-api.github.io/app_api/tech_details/ApiScopes.html)
	- [AppEcosystemV2 APIs](https://cloud-py-api.github.io/app_api/tech_details/api/index.html)
    - [Authentication](https://cloud-py-api.github.io/app_api/tech_details/Authentication.html)
    - [Deployment](https://cloud-py-api.github.io/app_api/tech_details/Deployment.html)
- [Contribute](https://github.com/cloud-py-api/app_api/blob/main/.github/CONTRIBUTING.md)
	- [Discussions](https://github.com/cloud-py-api/app_api/discussions)
	- [Issues](https://github.com/cloud-py-api/app_api/issues)
    - [Setting up dev environment](https://cloud-py-api.github.io/app_api/DevSetup.html)
- [Changelog](https://github.com/cloud-py-api/app_api/blob/main/CHANGELOG.md)

## ExApps list

Here is a list of the Nextcloud ExApps, using AppEcosystemV2:

| Name          | Language | Type        | Description                                 | Link                                                    |
|---------------|----------|-------------|---------------------------------------------|---------------------------------------------------------|
| nc_py_api     | Python   | library     | Python library for Nextcloud AppEcosystemV2 | [GitHub](https://github.com/cloud-py-api/nc_py_api)     |	
| upscaler_demo | Python   | application | Image UpScaler demonstration                | [GitHub](https://github.com/cloud-py-api/upscaler_demo) |

### Support

We appreciate any support for this project:

- ⭐ Star our work on GitHub (it helps us a lot)
- ❗ Create an Issue or feature request (bring to us an excellent idea)
- 💁 Resolve an Issue and create a Pull Request (contribute to this project)
- 🧑‍💻 Develop your own ExApp and share it to world (it will be listed above)

In closing, we are genuinely excited about the future of the AppEcosystem Project and the potential 
it holds for transforming the way applications are developed and experienced within Nextcloud. 

As we embark on this journey, we extend our warmest invitation to you — developers, thinkers, creators, and 
visionaries — to join us in shaping a more versatile, stable, and secure app landscape.

#### Your insights, suggestions, and contributions are invaluable to us.
