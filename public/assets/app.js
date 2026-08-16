const menuBtn=document.getElementById('menuBtn');const sidebar=document.getElementById('sidebar');
if(menuBtn&&sidebar){menuBtn.addEventListener('click',()=>sidebar.classList.toggle('open'));document.querySelectorAll('.nav-item').forEach(item=>item.addEventListener('click',()=>sidebar.classList.remove('open')))}

const modal=document.getElementById('studentModal');
const openButtons=document.querySelectorAll('[data-new-student]');
const closeButtons=document.querySelectorAll('[data-close-modal]');
openButtons.forEach(btn=>btn.addEventListener('click',()=>modal?.classList.add('show')));
closeButtons.forEach(btn=>btn.addEventListener('click',()=>modal?.classList.remove('show')));
modal?.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('show')});

const form=document.getElementById('studentForm');
const tableBody=document.querySelector('#studentsTable tbody');
const emptyRow=document.getElementById('emptyStudents');
const countEl=document.querySelector('[data-student-count]');
const STORAGE_KEY='hache_natacion_demo_students';

function loadStudents(){try{return JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]')}catch{return[]}}
function saveStudents(list){localStorage.setItem(STORAGE_KEY,JSON.stringify(list))}
function renderStudents(){const students=loadStudents();if(!tableBody)return;tableBody.querySelectorAll('tr[data-student]').forEach(row=>row.remove());emptyRow?.classList.toggle('hidden',students.length>0);students.forEach(s=>{const tr=document.createElement('tr');tr.dataset.student='1';tr.innerHTML=`<td><strong>${escapeHtml(s.nombre)}</strong><small class="cell-muted">${escapeHtml(s.whatsapp)}</small></td><td>${escapeHtml(s.plan)}</td><td><span class="status active">Activo</span><small class="cell-muted">${s.pago?'Con acceso':'Sin acceso · pago pendiente'}</small></td><td>${s.pago?'Registrado':'Pendiente'}</td><td><button class="ghost-btn small-btn">Ver ficha</button></td>`;tableBody.appendChild(tr)});if(countEl)countEl.textContent=students.length}
function escapeHtml(value){return String(value).replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[c]))}
form?.addEventListener('submit',e=>{e.preventDefault();const data=new FormData(form);const students=loadStudents();students.unshift({nombre:data.get('nombre'),whatsapp:data.get('whatsapp'),plan:data.get('plan'),pago:data.get('pago')==='on'});saveStudents(students);form.reset();modal?.classList.remove('show');renderStudents()});
renderStudents();
