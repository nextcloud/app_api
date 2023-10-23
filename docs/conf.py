import os
import sys
from datetime import datetime

import sphinx_rtd_theme

dir_path = os.path.dirname(os.path.realpath(__file__))
sys.path.insert(0, os.path.abspath(dir_path + "/_ext"))
now = datetime.now()

extensions = [
    "sphinx.ext.extlinks",
    "sphinx.ext.intersphinx",
    "sphinx.ext.viewcode",
    "sphinx_copybutton",
    "sphinx_inline_tabs",
    "sphinx_issues",
    "sphinx_rtd_theme",
	"sphinxcontrib.mermaid",
]

# General information about the project.
project = "AppAPI"
copyright = str(now.year) + " Nextcloud GmbH"  # noqa

# The version info for the project you're documenting, acts as replacement for
# |version| and |release|, also used in various other places throughout the
# built documents.
#
# The short X.Y version.
version = "latest"
release = version

html_theme = "sphinx_rtd_theme"
html_theme_path = [sphinx_rtd_theme.get_html_theme_path()]

html_logo = "resources/logo.svg"

html_theme_options = {
    "display_version": False,
}

# If true, `todos` produce output, else they produce nothing.
todo_include_todos = False

# If true, Sphinx will warn about all references where the target cannot be found.
# Default is False. You can activate this mode temporarily using the -n command-line
# switch.
nitpicky = True

# Add any paths that contain custom static files (such as style sheets) here,
# relative to this directory. They are copied after the builtin static files,
# so a file named "default.css" will overwrite the builtin "default.css".
html_static_path = ["resources"]


def setup(app):
    app.add_js_file("js/script.js")
    app.add_css_file("css/styles.css")
    app.add_css_file("css/dark.css")
    app.add_css_file("css/light.css")


issues_github_path = "cloud-py-api/app_api"
