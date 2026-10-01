# Binary Search Tree Implementation
class Node:
    def __init__(self, key):
        self.left = None
        self.right = None
        self.val = key

def insert(root, key):
    if root is None:
        return Node(key)
    if key < root.val:
        root.left = insert(root.left, key)
    else:
        root.right = insert(root.right, key)
    return root

def inorder(root):
    if root:
        inorder(root.left)
        print(root.val, end=' ')
        inorder(root.right)

if __name__ == '__main__':
    r = Node(50)
    for x in [30, 20, 40, 70, 60, 80]:
        insert(r, x)
    print('Inorder traversal:')
    inorder(r)
