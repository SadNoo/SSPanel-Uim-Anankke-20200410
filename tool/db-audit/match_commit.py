#!/usr/bin/env python3
"""用 collect.sh 生成的 file-hashes.txt，在本仓库历史中找出与服务器代码最接近的提交。

用法：python3 tool/db-audit/match_commit.py <file-hashes.txt> [显示前 N 名，默认 5]
"""
import hashlib
import subprocess
import sys

PATHS = ["app", "config", "resources/views"]


def git(*args):
    return subprocess.run(["git", *args], check=True, capture_output=True, text=True).stdout


def main():
    hashes_file = sys.argv[1]
    top = int(sys.argv[2]) if len(sys.argv) > 2 else 5

    server = {}
    with open(hashes_file, encoding="utf-8") as f:
        for line in f:
            parts = line.rstrip("\n").split(None, 1)
            if len(parts) == 2:
                server[parts[1].lstrip("*").removeprefix("./")] = parts[0]

    # blob id -> 文件内容的 sha1（与 sha1sum 结果一致），同一 blob 只计算一次
    cache = {}
    batch = subprocess.Popen(["git", "cat-file", "--batch"], stdin=subprocess.PIPE, stdout=subprocess.PIPE)

    def content_sha1(blob):
        if blob not in cache:
            batch.stdin.write(blob.encode() + b"\n")
            batch.stdin.flush()
            size = int(batch.stdout.readline().split()[2])
            data = batch.stdout.read(size)
            batch.stdout.read(1)
            cache[blob] = hashlib.sha1(data).hexdigest()
        return cache[blob]

    results = []
    for commit in git("rev-list", "--all").split():
        same = 0
        for line in git("ls-tree", "-r", commit, "--", *PATHS).splitlines():
            meta, path = line.split("\t", 1)
            blob = meta.split()[2]
            if path in server and content_sha1(blob) == server[path]:
                same += 1
        results.append((same, commit))

    results.sort(reverse=True)
    for same, commit in results[:top]:
        info = git("log", "-1", "--format=%ad %s", "--date=short", commit).strip()
        print(f"{same}/{len(server)} 个文件一致  {commit[:10]}  {info}")


if __name__ == "__main__":
    main()
