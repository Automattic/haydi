/** @type {import('jest').Config} */
module.exports = {
    testMatch: ['**/tests/unit/js/**/*.test.js'],
    testEnvironment: 'node',
    // Git worktrees under .claude/ are full checkouts of this repo; without this
    // Jest discovers every suite twice and haste reports a name collision.
    modulePathIgnorePatterns: ['<rootDir>/.claude/worktrees/'],
    testPathIgnorePatterns: ['/node_modules/', '<rootDir>/.claude/worktrees/'],
};
