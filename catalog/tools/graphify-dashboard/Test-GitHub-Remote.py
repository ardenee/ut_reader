"""Deterministic GitHub remote and Graphify card integration acceptance tests."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("graphify_github_remote", ROOT / "GitHub-Remote.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def git(folder, *args):
    subprocess.run(["git", "-C", str(folder), *args], check=True, capture_output=True, timeout=8)


class GitHubRemoteTest(unittest.TestCase):
    def test_supported_origins(self):
        for value in (
            "https://github.com/ardenee/ut_reader.git",
            "git@github.com:ardenee/ut_reader.git",
            "ssh://git@github.com/ardenee/ut_reader.git",
            "https://someone:secret@github.com/ardenee/ut_reader.git",
        ):
            self.assertEqual(module.parse_github_origin(value), "ardenee/ut_reader")

    def test_other_hosts_and_invalid_path_are_rejected(self):
        for value in (
            "https://gitlab.com/ardenee/ut_reader",
            "https://github.com.evil.invalid/ardenee/ut_reader",
            "https://github.com/owner/repo/tree/main",
            "https://github.com/owner/../repo",
            "file:///C:/source/ut_reader",
            "C:/Users/arden/source/repos/ut_reader",
            "",
        ):
            self.assertIsNone(module.parse_github_origin(value), value)

    def test_untracked_sibling_does_not_inherit_parent_repo(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            git(root, "init", "-q")
            git(root, "remote", "add", "origin", "https://github.com/example/umbrella.git")
            tracked = root / "tracked"
            tracked.mkdir()
            (tracked / "file.cpp").write_text("int main(){}", encoding="utf8")
            git(root, "add", "tracked/file.cpp")
            orphan = root / "orphan"
            orphan.mkdir()
            (orphan / "file.cpp").write_text("int f(){}", encoding="utf8")

            self.assertEqual(module.github_repository_for_source(str(root)), "example/umbrella")
            self.assertEqual(module.github_repository_for_source(str(tracked)), "example/umbrella")
            self.assertIsNone(module.github_repository_for_source(str(orphan)))

            nested = orphan / "independent"
            nested.mkdir()
            git(nested, "init", "-q")
            git(nested, "remote", "add", "origin", "git@github.com:example/independent.git")
            self.assertEqual(module.github_repository_for_source(str(nested)), "example/independent")

    def test_missing_source(self):
        self.assertIsNone(module.github_repository_for_source(""))
        self.assertIsNone(module.github_repository_for_source(str(ROOT / "does-not-exist")))

    def test_dashboard_has_button_order_and_never_links_missing_repo(self):
        text = (ROOT / "index.html").read_text(encoding="utf8")
        match = re.search(r"const projects=(.*?);\s*const masters=", text, re.S)
        self.assertIsNotNone(match)
        projects = json.loads(match.group(1))
        self.assertGreater(len(projects), 0)
        self.assertIn("acts.append(link,cli,diagram)", text)
        self.assertIn("diagram.disabled=true", text)
        self.assertIn("http://127.0.0.1:3001/", text)
        self.assertIn('id="gitdiagram-nav"', text)
        by_name = {project["id"]: project for project in projects}
        self.assertEqual(by_name["unrealdb"]["gitdiagram_repo"], "ardenee/ut_reader")
        self.assertEqual(by_name["ue1-ut432"]["gitdiagram_repo"], "ardenee/UT99src-ext")
        self.assertEqual(by_name["ue1-engine-source"]["gitdiagram_repo"], "ardenee/UE1")
        self.assertEqual(by_name["drivemaster-drivemaster"]["gitdiagram_repo"], "ardenee/drivemaster")
        self.assertIsNone(by_name["mapmanager-map-manager"]["gitdiagram_repo"])
        self.assertIsNone(by_name["mememe-mememe"]["gitdiagram_repo"])
        self.assertGreaterEqual(sum(bool(project["gitdiagram_repo"]) for project in projects), 4)


if __name__ == "__main__":
    unittest.main(verbosity=2)
