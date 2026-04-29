Fix GitHub issue #$ARGUMENTS

Follow these steps:

1. **Fetch the issue**: Use `gh issue view $ARGUMENTS` to read the issue details from the GitHub repository. Understand what the bug or feature request is about.

2. **Create a branch**: Create and checkout a new branch named `fix-$ARGUMENTS` from main:
   ```
   git checkout -b fix-$ARGUMENTS
   ```

3. **Write a failing test**: Based on the issue description, write a test that reproduces the problem. The test should fail initially, demonstrating the bug exists.

4. **Run the test**: Execute the failing test to confirm it fails as expected. If the test requires a database and fails due to connection issues, that's acceptable - focus on the test logic being correct.

5. **Diagnose the issue**: Analyze the codebase to understand why the bug occurs. Look at the relevant source files and trace through the logic.

6. **Implement the fix**: Make the necessary code changes to fix the issue. Keep changes minimal and focused.

7. **Verify the fix**: Run the test again to confirm it now passes (or would pass with a database connection).

8. **Commit and push**: Stage all changes, commit with a descriptive message referencing the issue number, and push the branch:
   ```
   git add -A
   git commit -m "Fix #$ARGUMENTS: <brief description>"
   git push -u origin fix-$ARGUMENTS
   ```

9. **Report**: Summarize what was done, what the fix was, and provide the branch name for creating a PR.
