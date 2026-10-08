const fs = require('fs');

let content = fs.readFileSync('src/data/curriculumManifest.ts', 'utf8');

// We only want to modify Unit 1.
// We can parse the file or do a precise regex replace for unit 1 items.
// A simpler way is to replace u1l6 -> u1l7, u1l5 -> u1l6, etc., down to u1l1 -> u1l2.
// And similarly for lessonNumber: X and paths.

const replacements = [
  [6, 7],
  [5, 6],
  [4, 5],
  [3, 4],
  [2, 3],
  [1, 2]
];

for (const [oldNum, newNum] of replacements) {
  // Replace id: 'u1lX' with id: 'u1lY'
  content = content.replace(new RegExp(`id: 'u1l${oldNum}'`, 'g'), `id: 'u1l${newNum}'`);
  
  // Replace id: 'u1lXsY' with id: 'u1lYsY'
  content = content.replace(new RegExp(`id: 'u1l${oldNum}s(\\d+)'`, 'g'), `id: 'u1l${newNum}s$1'`);

  // Replace lessonNumber: X within u1lY block? 
  // It's safer to just replace all instances of "path: '/unit/1/lesson/X/section"
  content = content.replace(new RegExp(`path: '/unit/1/lesson/${oldNum}/section/`, 'g'), `path: '/unit/1/lesson/${newNum}/section/`);
  
  // Replace lessonNumber: X where it occurs after id: 'u1lY'
  content = content.replace(new RegExp(`id: 'u1l${newNum}', lessonNumber: ${oldNum},`, 'g'), `id: 'u1l${newNum}', lessonNumber: ${newNum},`);
}

fs.writeFileSync('src/data/curriculumManifest.ts', content);
console.log('Manifest updated');
